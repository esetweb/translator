<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationTarget;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Exception\SiteNotFoundException;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Turns the site configuration into TranslationTarget objects.
 *
 * Unlike l10nmgr this never treats sys_language_uid as a global key. Every
 * language is always resolved in the context of its site, so 80 sites with
 * languageId 0 produce 80 distinct, addressable targets.
 */
class SiteLanguageService
{
    /** @var SiteFinder */
    protected $siteFinder;

    /** @var array<string, TranslationTarget[]>|null */
    protected $runtimeCache;

    public function __construct(SiteFinder $siteFinder)
    {
        $this->siteFinder = $siteFinder;
    }

    /**
     * All targets of all sites, keyed by target key.
     *
     * @return TranslationTarget[]
     */
    public function getAllTargets(): array
    {
        if ($this->runtimeCache !== null) {
            return $this->runtimeCache;
        }

        $targets = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            foreach ($this->getTargetsOfSite($site) as $target) {
                $targets[$target->getKey()] = $target;
            }
        }

        return $this->runtimeCache = $targets;
    }

    /**
     * @return TranslationTarget[]
     */
    public function getTargetsOfSite(Site $site): array
    {
        $targets = [];
        foreach ($site->getAllLanguages() as $language) {
            $target = TranslationTarget::fromSiteLanguage($site, $language);
            $targets[$target->getKey()] = $target;
        }

        return $targets;
    }

    /**
     * @return TranslationTarget[]
     */
    public function getTargetsBySiteIdentifier(string $siteIdentifier): array
    {
        try {
            return $this->getTargetsOfSite($this->siteFinder->getSiteByIdentifier($siteIdentifier));
        } catch (SiteNotFoundException $exception) {
            return [];
        }
    }

    /**
     * @return TranslationTarget[]
     */
    public function getTargetsForPage(int $pageUid): array
    {
        $site = $this->getSiteForPage($pageUid);

        return $site === null ? [] : $this->getTargetsOfSite($site);
    }

    public function getSiteForPage(int $pageUid): ?Site
    {
        try {
            return $this->siteFinder->getSiteByPageId($pageUid);
        } catch (SiteNotFoundException $exception) {
            return null;
        }
    }

    /**
     * The default language target of the site the page belongs to. This is the
     * natural translation *source* and is exactly what l10nmgr cannot express.
     */
    public function getDefaultTargetForPage(int $pageUid): ?TranslationTarget
    {
        $site = $this->getSiteForPage($pageUid);
        if ($site === null) {
            return null;
        }

        return TranslationTarget::fromSiteLanguage($site, $site->getDefaultLanguage());
    }

    /**
     * The default-language target of the page this page was copied from.
     *
     * TYPO3 stamps t3_origuid on copy/paste, so for a page copied out of the
     * English site for localization this returns the English target - even for
     * pages copied long ago, without any extra bookkeeping.
     */
    public function getOriginTargetForPage(int $pageUid): ?TranslationTarget
    {
        $origUidField = (string)($GLOBALS['TCA']['pages']['ctrl']['origUid'] ?? '');
        if ($origUidField === '') {
            return null;
        }
        $page = BackendUtility::getRecord('pages', $pageUid, 'uid,' . $origUidField);
        $originUid = (int)($page[$origUidField] ?? 0);
        if ($originUid <= 0 || $originUid === $pageUid) {
            return null;
        }

        return $this->getDefaultTargetForPage($originUid);
    }

    public function findTarget(string $key): ?TranslationTarget
    {
        return $this->getAllTargets()[$key] ?? null;
    }

    /**
     * Resolves a target and throws when it does not exist, so callers dealing
     * with user input fail loudly instead of silently exporting the wrong data.
     */
    public function getTarget(string $key): TranslationTarget
    {
        $target = $this->findTarget($key);
        if ($target === null) {
            throw new \RuntimeException(
                sprintf('Translation target "%s" does not exist in any site configuration.', $key),
                1710000010
            );
        }

        return $target;
    }

    public function getTargetForSiteAndLanguage(string $siteIdentifier, int $languageId): ?TranslationTarget
    {
        return $this->findTarget($siteIdentifier . TranslationTarget::KEY_SEPARATOR . $languageId);
    }

    /**
     * Targets grouped by site identifier, ready for an optgroup based picker.
     *
     * @param TranslationTarget[] $targets
     * @return array<string, array{siteTitle: string, targets: TranslationTarget[]}>
     */
    public function groupBySite(array $targets): array
    {
        $grouped = [];
        foreach ($targets as $target) {
            $identifier = $target->getSiteIdentifier();
            if (!isset($grouped[$identifier])) {
                $grouped[$identifier] = [
                    'siteTitle' => $target->getSiteTitle(),
                    'targets' => [],
                ];
            }
            $grouped[$identifier]['targets'][] = $target;
        }
        ksort($grouped);

        return $grouped;
    }
}
