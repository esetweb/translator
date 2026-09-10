<?php

declare(strict_types=1);

namespace ESET\Translator\ContextMenu;

use ESET\Translator\Provider\ProviderRegistry;
use ESET\Translator\Service\PermissionService;
use ESET\Translator\Service\SiteLanguageService;
use TYPO3\CMS\Backend\ContextMenu\ItemProviders\AbstractProvider;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Adds an "ESET" submenu to the page tree context menu.
 */
class PageTreeItemProvider extends AbstractProvider
{
    /** @var array<string, array<string, mixed>> */
    protected $itemsConfiguration = [
        'eset' => [
            'type' => 'submenu',
            'label' => 'LLL:EXT:eset_translator/Resources/Private/Language/locallang.xlf:contextMenu.eset',
            'iconIdentifier' => 'eset-translator-module',
            'childItems' => [
                'esetRequestTranslation' => [
                    'type' => 'item',
                    'label' => 'LLL:EXT:eset_translator/Resources/Private/Language/locallang.xlf:contextMenu.requestTranslation',
                    'iconIdentifier' => 'eset-translator-translate',
                    'callbackAction' => 'requestTranslation',
                ],
                'esetExchange' => [
                    'type' => 'item',
                    'label' => 'LLL:EXT:eset_translator/Resources/Private/Language/locallang.xlf:contextMenu.exchange',
                    'iconIdentifier' => 'eset-translator-exchange',
                    'callbackAction' => 'exchangeTranslation',
                ],
                'esetJobs' => [
                    'type' => 'item',
                    'label' => 'LLL:EXT:eset_translator/Resources/Private/Language/locallang.xlf:contextMenu.jobs',
                    'iconIdentifier' => 'eset-translator-job',
                    'callbackAction' => 'openJobs',
                ],
            ],
        ],
    ];

    public function canHandle(): bool
    {
        return $this->table === 'pages';
    }

    public function getPriority(): int
    {
        return 45;
    }

    /**
     * @param array<string, mixed> $items
     * @return array<string, mixed>
     */
    public function addItems(array $items): array
    {
        $this->initDisabledItems();
        $localItems = $this->prepareItems($this->itemsConfiguration);

        return $items + $localItems;
    }

    protected function canRender(string $itemName, string $type): bool
    {
        if (in_array($itemName, $this->disabledItems, true)) {
            return false;
        }
        $pageUid = (int)$this->identifier;
        if ($pageUid <= 0) {
            return false;
        }

        switch ($itemName) {
            case 'eset':
            case 'esetJobs':
                return $this->getPermissionService()->canReadPage($pageUid);
            case 'esetExchange':
                // Translation writes into this page - require edit access.
                return $this->getPermissionService()->canEditPage($pageUid)
                    && $this->getSiteLanguageService()->getSiteForPage($pageUid) !== null
                    && $this->getPermissionService()->getAllowedTargets() !== [];
            case 'esetRequestTranslation':
                // Automated translation also needs a configured provider.
                return $this->getProviderRegistry()->hasAnyAvailable()
                    && $this->getPermissionService()->canEditPage($pageUid)
                    && $this->getSiteLanguageService()->getSiteForPage($pageUid) !== null
                    && $this->getPermissionService()->getAllowedTargets() !== [];
            default:
                return false;
        }
    }

    /**
     * @return array<string, string>
     */
    protected function getAdditionalAttributes(string $itemName): array
    {
        return [
            'data-callback-module' => 'TYPO3/CMS/EsetTranslator/ContextMenuActions',
            'data-page-uid' => (string)$this->identifier,
        ];
    }

    protected function getPermissionService(): PermissionService
    {
        return GeneralUtility::makeInstance(PermissionService::class);
    }

    protected function getSiteLanguageService(): SiteLanguageService
    {
        return GeneralUtility::makeInstance(SiteLanguageService::class);
    }

    protected function getProviderRegistry(): ProviderRegistry
    {
        return GeneralUtility::makeInstance(ProviderRegistry::class);
    }
}
