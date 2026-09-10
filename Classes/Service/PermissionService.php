<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationTarget;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Answers "which (site, language) pairs may this editor translate from / into".
 *
 * Why this class exists:
 * TYPO3 core permissions for languages live in be_groups.allowed_languages,
 * a comma separated list of sys_language_uid values. Language 0 is implicitly
 * allowed for everybody and cannot be restricted, and a uid is shared by every
 * site that uses it. On an installation with 80 sites whose default language
 * (languageId 0) carries a different typo3Language per site, that model
 * collapses into a single meaningless permission blob.
 *
 * Therefore permissions are resolved here as target keys ("<site>:<languageId>").
 *
 * Default model (NO TSconfig needed): a group may translate a site when the
 * site root is inside its page-tree mounts; overlay languages follow the group's
 * "Languages" (allowed_languages) list; languageId 0 is always writable, gated by
 * the site mount + the page edit right. Admins may do anything.
 *
 * Optional user TSconfig override for finer control:
 *
 *   tx_esettranslator {
 *       allowedSources = eset-cz:*, eset-sk:*   # sources the editor may read
 *       allowedTargets = eset-cz:1, eset-sk:*   # targets the editor may write
 *       deniedTargets  = eset-sk:9
 *       ignoreCoreLanguagePermissions = 1       # ignore allowed_languages
 *       denyDefaultLanguageAsTarget   = 1       # forbid writing languageId 0
 *   }
 */
class PermissionService
{
    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var array<string, mixed>|null */
    protected $tsConfigCache;

    public function __construct(SiteLanguageService $siteLanguageService)
    {
        $this->siteLanguageService = $siteLanguageService;
    }

    /**
     * @return TranslationTarget[]
     */
    public function getAllowedSources(): array
    {
        return $this->filterTargets(
            $this->siteLanguageService->getAllTargets(),
            $this->getConfiguredPatterns('allowedSources'),
            $this->getConfiguredPatterns('deniedSources'),
            false
        );
    }

    /**
     * @return TranslationTarget[]
     */
    public function getAllowedTargets(): array
    {
        return $this->filterTargets(
            $this->siteLanguageService->getAllTargets(),
            $this->getConfiguredPatterns('allowedTargets'),
            $this->getConfiguredPatterns('deniedTargets'),
            true
        );
    }

    /**
     * Targets available for a concrete page, i.e. the languages of the site the
     * page belongs to, reduced by the editor's permissions.
     *
     * @return TranslationTarget[]
     */
    public function getAllowedTargetsForPage(int $pageUid): array
    {
        $siteTargets = $this->siteLanguageService->getTargetsForPage($pageUid);
        $allowed = $this->getAllowedTargets();

        return array_intersect_key($siteTargets, $allowed);
    }

    public function isSourceAllowed(TranslationTarget $target): bool
    {
        return isset($this->getAllowedSources()[$target->getKey()]);
    }

    public function isTargetAllowed(TranslationTarget $target): bool
    {
        return isset($this->getAllowedTargets()[$target->getKey()]);
    }

    /**
     * Page level check: the editor must be able to read the page and, for
     * imports, must be allowed to edit its content.
     */
    public function canReadPage(int $pageUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $page = BackendUtility::getRecord('pages', $pageUid);

        return $page !== null && $backendUser->doesUserHaveAccess($page, Permission::PAGE_SHOW);
    }

    public function canEditPage(int $pageUid): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $page = BackendUtility::getRecord('pages', $pageUid);

        return $page !== null && $backendUser->doesUserHaveAccess($page, Permission::CONTENT_EDIT);
    }

    /**
     * Guard used by every entry point (buttons, context menu, AJAX, CLI).
     *
     * @throws \RuntimeException when the combination is not permitted
     */
    public function assertTranslationAllowed(int $pageUid, TranslationTarget $source, TranslationTarget $target): void
    {
        // Translation writes into this page (in place, or as an overlay on it),
        // so edit access is required - not just read.
        if (!$this->canEditPage($pageUid)) {
            throw new \RuntimeException(
                sprintf('You may view page %d but not edit its content, so it cannot be translated here.', $pageUid),
                1710000020
            );
        }
        if (!$this->isSourceAllowed($source)) {
            throw new \RuntimeException(sprintf('Access denied to translation source "%s".', $source->getKey()), 1710000021);
        }
        if (!$this->isTargetAllowed($target)) {
            throw new \RuntimeException(sprintf('Access denied to translation target "%s".', $target->getKey()), 1710000022);
        }
        if ($source->equals($target)) {
            throw new \RuntimeException(
                'Source and target are the same (site, language). Pick the language the page is currently written in as the source - for a page copied from the English site that is the English site\'s default language.',
                1710000023
            );
        }
    }

    /**
     * @param TranslationTarget[] $targets
     * @param string[] $allowPatterns
     * @param string[] $denyPatterns
     * @return TranslationTarget[]
     */
    protected function filterTargets(array $targets, array $allowPatterns, array $denyPatterns, bool $isWriteAccess): array
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return [];
        }
        if ($backendUser->isAdmin()) {
            return $this->applyDenyList($targets, $denyPatterns);
        }

        $result = [];
        foreach ($targets as $key => $target) {
            if ($allowPatterns !== []) {
                if (!$this->matchesAnyPattern($key, $allowPatterns)) {
                    continue;
                }
            } elseif (!$this->isAllowedByCorePermissions($target, $isWriteAccess)) {
                continue;
            }
            if (!$this->isSiteRootAccessible($target)) {
                continue;
            }
            $result[$key] = $target;
        }

        return $this->applyDenyList($result, $denyPatterns);
    }

    /**
     * @param TranslationTarget[] $targets
     * @param string[] $denyPatterns
     * @return TranslationTarget[]
     */
    protected function applyDenyList(array $targets, array $denyPatterns): array
    {
        if ($denyPatterns === []) {
            return $targets;
        }
        foreach (array_keys($targets) as $key) {
            if ($this->matchesAnyPattern((string)$key, $denyPatterns)) {
                unset($targets[$key]);
            }
        }

        return $targets;
    }

    /**
     * The default permission model - backend groups only, no TSconfig:
     *
     *  - which SITES a group may translate  => its page-tree mounts. The site
     *    root must be inside a DB/web mount (isSiteRootAccessible()). This is
     *    what disambiguates "site A languageId 0" from "site B languageId 0",
     *    which the single global "Default" language checkbox cannot.
     *  - which OVERLAY languages (id > 0)    => be_groups "Languages"
     *    (allowed_languages), i.e. core checkLanguageAccess(). Empty = all.
     *  - languageId 0 (in place translation) => always allowed, exactly like
     *    core; the real gate is the site mount above plus the page-level edit
     *    right checked when the job is created.
     *
     * TSconfig (tx_esettranslator.allowedTargets / deniedTargets / ...) is an
     * optional override for finer control and is not required.
     */
    protected function isAllowedByCorePermissions(TranslationTarget $target, bool $isWriteAccess): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ((bool)$this->getTsConfigValue('ignoreCoreLanguagePermissions')) {
            return true;
        }
        if ($target->isDefaultLanguage()) {
            if ($isWriteAccess && (bool)$this->getTsConfigValue('denyDefaultLanguageAsTarget')) {
                return false;
            }

            return true;
        }

        return $backendUser->checkLanguageAccess($target->getLanguageId());
    }

    protected function isSiteRootAccessible(TranslationTarget $target): bool
    {
        $backendUser = $this->getBackendUser();
        if ($backendUser === null) {
            return false;
        }
        if ($backendUser->isAdmin()) {
            return true;
        }
        $rootPageId = $target->getRootPageId();
        if ($rootPageId === 0) {
            return false;
        }

        return $backendUser->isInWebMount($rootPageId) !== null;
    }

    /**
     * Supports exact keys and wildcards: "eset-cz:*", "*:1", "*".
     *
     * @param string[] $patterns
     */
    protected function matchesAnyPattern(string $key, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($pattern === '*' || $pattern === $key) {
                return true;
            }
            $regex = '/^' . str_replace('\*', '.*', preg_quote($pattern, '/')) . '$/i';
            if (preg_match($regex, $key) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return string[]
     */
    protected function getConfiguredPatterns(string $key): array
    {
        $value = (string)$this->getTsConfigValue($key);
        if (trim($value) === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }

    /**
     * @return mixed
     */
    protected function getTsConfigValue(string $key)
    {
        if ($this->tsConfigCache === null) {
            $backendUser = $this->getBackendUser();
            $this->tsConfigCache = $backendUser === null
                ? []
                : (array)($backendUser->getTSConfig()['tx_esettranslator.'] ?? []);
        }

        return $this->tsConfigCache[$key] ?? null;
    }

    protected function getBackendUser(): ?BackendUserAuthentication
    {
        return $GLOBALS['BE_USER'] ?? null;
    }
}
