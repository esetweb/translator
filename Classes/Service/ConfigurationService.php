<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Typed access to the extension configuration.
 */
class ConfigurationService
{
    public const EXTENSION_KEY = 'eset_translator';

    /** @var array<string, mixed> */
    protected $configuration = [];

    public function __construct(ExtensionConfiguration $extensionConfiguration)
    {
        try {
            $this->configuration = (array)$extensionConfiguration->get(self::EXTENSION_KEY);
        } catch (\Throwable $exception) {
            $this->configuration = [];
        }
    }

    public function get(string $key, string $default = ''): string
    {
        $value = $this->configuration[$key] ?? $default;

        return is_scalar($value) ? (string)$value : $default;
    }

    public function getBool(string $key, bool $default = false): bool
    {
        if (!isset($this->configuration[$key])) {
            return $default;
        }

        return (bool)$this->configuration[$key];
    }

    public function getInt(string $key, int $default = 0): int
    {
        return isset($this->configuration[$key]) ? (int)$this->configuration[$key] : $default;
    }

    /**
     * @return string[]
     */
    public function getList(string $key, array $default = []): array
    {
        $value = trim($this->get($key));
        if ($value === '') {
            return $default;
        }

        return GeneralUtility::trimExplode(',', $value, true);
    }

    /**
     * Tables scanned for translatable content.
     *
     * @return string[]
     */
    public function getTranslatableTables(): array
    {
        return $this->getList('translatableTables', ['pages', 'tt_content']);
    }

    /**
     * Fields never exported, e.g. technical identifiers.
     *
     * @return string[]
     */
    public function getExcludedFields(): array
    {
        return $this->getList('excludedFields', [
            'pages.slug',
            'pages.alias',
            'pages.url',
            'pages.tx_esettranslator_note',
            'tt_content.pi_flexform',
        ]);
    }

    public function isFieldExcluded(string $table, string $field): bool
    {
        $excluded = $this->getExcludedFields();

        return in_array($table . '.' . $field, $excluded, true)
            || in_array('*.' . $field, $excluded, true);
    }

    public function getDefaultProvider(): string
    {
        return $this->get('defaultProvider', 'deepl');
    }

    /**
     * Language a page is assumed to be written in when the copy origin cannot be
     * resolved. A target key ("us:0") or a bare code ("en"); empty = off.
     */
    public function getDefaultSourceLanguage(): string
    {
        return trim($this->get('defaultSourceLanguage', ''));
    }

    public function getDefaultFormat(): string
    {
        return $this->get('defaultFormat', 'xliff');
    }

    /**
     * Number of translation units sent to a provider in one API request.
     */
    public function getChunkSize(): int
    {
        $size = $this->getInt('chunkSize', 40);

        return $size > 0 ? $size : 40;
    }

    public function getMaxDepth(): int
    {
        return $this->getInt('maxDepth', 10);
    }

    /**
     * When no provider is configured, the UI must still offer the manual
     * XML download instead of dead-ending the editor.
     */
    public function isManualFallbackEnabled(): bool
    {
        return $this->getBool('allowManualFallback', true);
    }

    public function getStorageFolder(): string
    {
        $folder = trim($this->get('storageFolder', 'typo3temp/var/eset_translator'), '/');
        if ($folder === '') {
            $folder = 'typo3temp/var/eset_translator';
        }
        $absolute = Environment::getPublicPath() . '/' . $folder;
        if (!is_dir($absolute)) {
            GeneralUtility::mkdir_deep($absolute);
        }

        return $absolute;
    }

    public function getProviderTimeout(): int
    {
        $timeout = $this->getInt('providerTimeout', 30);

        return $timeout > 0 ? $timeout : 30;
    }
}
