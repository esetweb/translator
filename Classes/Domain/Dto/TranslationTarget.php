<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Dto;

use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Immutable description of one translation source/target.
 *
 * The whole point of this extension: a translation endpoint is NOT identified by
 * sys_language_uid alone. With 80 sites that all use languageId 0 for their own
 * localized default language, sys_language_uid 0 is ambiguous. A target is only
 * unique as the pair (site identifier, languageId).
 */
final class TranslationTarget implements \JsonSerializable
{
    public const KEY_SEPARATOR = ':';

    /** @var string */
    private $siteIdentifier;

    /** @var int */
    private $languageId;

    /** @var string */
    private $title;

    /** @var string TYPO3 language key, e.g. "cs", "sk", "default" */
    private $typo3Language;

    /** @var string Locale as configured in the site, e.g. "cs_CZ.UTF-8" */
    private $locale;

    /** @var string hreflang, e.g. "cs-CZ" */
    private $hreflang;

    /** @var string Two letter ISO code, e.g. "cs" */
    private $isoCode;

    /** @var int */
    private $rootPageId;

    /** @var string */
    private $siteTitle;

    public function __construct(
        string $siteIdentifier,
        int $languageId,
        string $title = '',
        string $typo3Language = '',
        string $locale = '',
        string $hreflang = '',
        string $isoCode = '',
        int $rootPageId = 0,
        string $siteTitle = ''
    ) {
        $this->siteIdentifier = $siteIdentifier;
        $this->languageId = $languageId;
        $this->title = $title;
        $this->typo3Language = $typo3Language;
        $this->locale = $locale;
        $this->hreflang = $hreflang;
        $this->isoCode = $isoCode;
        $this->rootPageId = $rootPageId;
        $this->siteTitle = $siteTitle;
    }

    public static function fromSiteLanguage(Site $site, SiteLanguage $language): self
    {
        $typo3Language = (string)$language->getTypo3Language();
        if ($typo3Language === '' || $typo3Language === 'default') {
            // A site language flagged as "default" still has a real language,
            // derived from the locale / hreflang configured for that site.
            $typo3Language = self::deriveIsoCode($language) ?: 'default';
        }

        return new self(
            $site->getIdentifier(),
            $language->getLanguageId(),
            $language->getTitle(),
            $typo3Language,
            (string)$language->getLocale(),
            (string)$language->getHreflang(),
            self::deriveIsoCode($language),
            $site->getRootPageId(),
            self::resolveSiteTitle($site)
        );
    }

    /**
     * Best effort ISO 639-1 code. Site languages of localized default languages
     * frequently carry typo3Language "default", so the locale/hreflang wins.
     */
    private static function deriveIsoCode(SiteLanguage $language): string
    {
        $candidates = [
            (string)$language->getTwoLetterIsoCode(),
            (string)$language->getHreflang(),
            (string)$language->getLocale(),
            (string)$language->getTypo3Language(),
        ];

        foreach ($candidates as $candidate) {
            if ($candidate === '' || $candidate === 'default') {
                continue;
            }
            $normalized = strtolower(substr(str_replace('_', '-', $candidate), 0, 2));
            if (preg_match('/^[a-z]{2}$/', $normalized) === 1) {
                return $normalized;
            }
        }

        return '';
    }

    private static function resolveSiteTitle(Site $site): string
    {
        $title = (string)($site->getConfiguration()['websiteTitle'] ?? '');

        return $title !== '' ? $title : $site->getIdentifier();
    }

    /**
     * Unique, transport safe identifier, e.g. "eset-cz:0".
     */
    public function getKey(): string
    {
        return $this->siteIdentifier . self::KEY_SEPARATOR . $this->languageId;
    }

    public static function fromKey(string $key): self
    {
        $parts = explode(self::KEY_SEPARATOR, $key);
        if (count($parts) !== 2 || $parts[0] === '' || !is_numeric($parts[1])) {
            throw new \InvalidArgumentException(
                sprintf('"%s" is not a valid translation target key, expected "<siteIdentifier>:<languageId>".', $key),
                1710000001
            );
        }

        return new self($parts[0], (int)$parts[1]);
    }

    public function getSiteIdentifier(): string
    {
        return $this->siteIdentifier;
    }

    public function getLanguageId(): int
    {
        return $this->languageId;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getTypo3Language(): string
    {
        return $this->typo3Language;
    }

    public function getLocale(): string
    {
        return $this->locale;
    }

    public function getHreflang(): string
    {
        return $this->hreflang;
    }

    public function getIsoCode(): string
    {
        return $this->isoCode;
    }

    public function getRootPageId(): int
    {
        return $this->rootPageId;
    }

    public function getSiteTitle(): string
    {
        return $this->siteTitle;
    }

    /**
     * Language code handed over to translation providers / written into XLIFF.
     * Prefers the most specific form available.
     */
    public function getTranslationCode(): string
    {
        if ($this->hreflang !== '') {
            return $this->hreflang;
        }
        if ($this->isoCode !== '') {
            return $this->isoCode;
        }
        if ($this->typo3Language !== '' && $this->typo3Language !== 'default') {
            return $this->typo3Language;
        }

        return 'en';
    }

    public function getLabel(): string
    {
        return sprintf('%s – %s (%s)', $this->siteTitle, $this->title, $this->getTranslationCode());
    }

    public function isDefaultLanguage(): bool
    {
        return $this->languageId === 0;
    }

    public function equals(self $other): bool
    {
        return $this->getKey() === $other->getKey();
    }

    public function isSameSite(self $other): bool
    {
        return $this->siteIdentifier === $other->getSiteIdentifier();
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'key' => $this->getKey(),
            'site' => $this->siteIdentifier,
            'siteTitle' => $this->siteTitle,
            'languageId' => $this->languageId,
            'title' => $this->title,
            'typo3Language' => $this->typo3Language,
            'locale' => $this->locale,
            'hreflang' => $this->hreflang,
            'iso' => $this->isoCode,
            'rootPageId' => $this->rootPageId,
            'translationCode' => $this->getTranslationCode(),
            'label' => $this->getLabel(),
        ];
    }
}
