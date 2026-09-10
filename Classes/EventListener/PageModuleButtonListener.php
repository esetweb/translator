<?php

declare(strict_types=1);

namespace ESET\Translator\EventListener;

use ESET\Translator\Provider\ProviderRegistry;
use ESET\Translator\Service\PermissionService;
use ESET\Translator\Service\SiteLanguageService;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds "Request translation" and "Export / import translation" buttons to the
 * doc header of the page module.
 *
 * TYPO3 v10.4 has no ModifyButtonBarEvent (that PSR-14 event only exists from
 * v11), so this hooks into ButtonBar::getButtonsHook instead. Registered in
 * ext_localconf.php.
 */
class PageModuleButtonListener
{
    protected const SUPPORTED_ROUTES = ['web_layout'];

    /** @var IconFactory */
    protected $iconFactory;

    /** @var PermissionService */
    protected $permissionService;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var ProviderRegistry */
    protected $providerRegistry;

    public function __construct(
        IconFactory $iconFactory,
        PermissionService $permissionService,
        SiteLanguageService $siteLanguageService,
        ProviderRegistry $providerRegistry
    ) {
        $this->iconFactory = $iconFactory;
        $this->permissionService = $permissionService;
        $this->siteLanguageService = $siteLanguageService;
        $this->providerRegistry = $providerRegistry;
    }

    /**
     * @param array{buttons: array<string, array<int, array<int, mixed>>>} $params
     * @return array<string, array<int, array<int, mixed>>>
     */
    public function getButtons(array $params, ButtonBar $buttonBar): array
    {
        $buttons = $params['buttons'];

        try {
            $pageUid = $this->resolvePageUid();
            if ($pageUid === 0 || !$this->isPageModule() || !$this->isEligible($pageUid)) {
                return $buttons;
            }
        } catch (\Throwable $exception) {
            return $buttons;
        }

        // "Request translation" (automated) only appears when a translation
        // provider is actually configured. The manual export/import button is
        // always available.
        if ($this->providerRegistry->hasAnyAvailable()) {
            $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $buttonBar->makeLinkButton()
                ->setHref('#')
                ->setTitle($this->translate('button.requestTranslation'))
                ->setShowLabelText(true)
                ->setIcon($this->iconFactory->getIcon('eset-translator-translate', Icon::SIZE_SMALL))
                ->setDataAttributes([
                    'eset-translator-action' => 'request',
                    'eset-translator-page' => (string)$pageUid,
                ]);
        }

        $buttons[ButtonBar::BUTTON_POSITION_LEFT][5][] = $buttonBar->makeLinkButton()
            ->setHref('#')
            ->setTitle($this->translate('button.exchange'))
            ->setShowLabelText(true)
            ->setIcon($this->iconFactory->getIcon('eset-translator-exchange', Icon::SIZE_SMALL))
            ->setDataAttributes([
                'eset-translator-action' => 'exchange',
                'eset-translator-page' => (string)$pageUid,
            ]);

        GeneralUtility::makeInstance(PageRenderer::class)
            ->loadRequireJsModule('TYPO3/CMS/EsetTranslator/TranslationWizard');

        return $buttons;
    }

    protected function isEligible(int $pageUid): bool
    {
        // Translation writes into this page, so require edit access. The page
        // must also belong to a configured site.
        return $this->permissionService->canEditPage($pageUid)
            && $this->siteLanguageService->getSiteForPage($pageUid) !== null
            && $this->permissionService->getAllowedTargets() !== [];
    }

    /**
     * The ButtonBar hook fires for every backend module; restrict to the page
     * module. v10.4 module routes expose the module name via the "moduleName"
     * route option (see ExtensionManagementUtility::addModule()).
     */
    protected function isPageModule(): bool
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return false;
        }
        $route = $request->getAttribute('route');
        if ($route !== null) {
            foreach (['moduleName', '_identifier'] as $option) {
                if (in_array((string)$route->getOption($option), self::SUPPORTED_ROUTES, true)) {
                    return true;
                }
            }
        }
        $parsedBody = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];
        $routePath = (string)($request->getQueryParams()['route'] ?? $parsedBody['route'] ?? '');

        return in_array($routePath, ['/module/web/layout', '/web/layout/'], true);
    }

    protected function resolvePageUid(): int
    {
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return 0;
        }
        $queryParams = $request->getQueryParams();
        $parsedBody = is_array($request->getParsedBody()) ? $request->getParsedBody() : [];

        return (int)($parsedBody['id'] ?? $queryParams['id'] ?? 0);
    }

    protected function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $key;
        }

        return $languageService->sL('LLL:EXT:eset_translator/Resources/Private/Language/locallang.xlf:' . $key);
    }
}
