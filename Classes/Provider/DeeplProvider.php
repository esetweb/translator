<?php
declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DeepL API (Free and Pro).
 *
 * Free API keys end with ":fx" and use a different host; this is detected
 * automatically so editors do not have to configure the endpoint.
 */
class DeeplProvider extends AbstractTranslationProvider
{
    public const IDENTIFIER = 'deepl';

    protected const HOST_PRO = 'https://api.deepl.com/v2/translate';
    protected const HOST_FREE = 'https://api-free.deepl.com/v2/translate';

    /**
     * @var array<string, string>
     */
    protected const TARGET_OVERRIDES = [
        'en' => 'EN-GB',
        'pt' => 'PT-PT',
    ];

    /**
     * Provider key => key in $GLOBALS['ESET_CONF_VARS']
     *
     * @var array<string, string>
     */
    protected const ESET_CONF_KEY_MAP = [
        'deeplApiKey' => 'deepl_api_key',
        'deeplApiUrl' => 'deepl_api_url',
        'deeplGlossaryId' => 'deepl_glossary_id',
    ];

    /**
     * Page uid from the calling code (options['pageId']), so the site can be resolved in the BE.
     *
     * @var int
     */
    protected $currentPageId = 0;

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'DeepL';
    }

    public function isAvailable(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function supports(TranslationTarget $source, TranslationTarget $target): bool
    {
        if (!parent::supports($source, $target)) {
            return false;
        }

        $supported = [
            'bg', 'cs', 'da', 'de', 'el', 'en', 'es', 'et', 'fi', 'fr', 'hu', 'id', 'it',
            'ja', 'ko', 'lt', 'lv', 'nb', 'nl', 'pl', 'pt', 'ro', 'ru', 'sk', 'sl', 'sv',
            'tr', 'uk', 'zh',
        ];

        return in_array($this->normalizeLanguageCode($source), $supported, true)
            && in_array($this->normalizeLanguageCode($target), $supported, true);
    }

    public function getBatchSize(): int
    {
        return min(50, parent::getBatchSize());
    }

    /**
     * Elements DeepL must never translate inside (inline CSS/JS in RTE or HTML CEs).
     */
    protected const IGNORE_TAGS = 'script,style,code,pre';

    /**
     * Options:
     *  - html: bool for the whole batch, or array keyed like $texts (per-text flag).
     *          When missing, markup is detected per text.
     */
    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }

        $this->currentPageId = (int)($options['pageId'] ?? 0);

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new TranslationProviderException('DeepL API key is not configured.', 1710000070);
        }

        $htmlFlags = $this->resolveHtmlFlags($texts, $options);

        // Everything goes through HTML mode: DeepL translates text nodes only and
        // leaves tags, attributes (class, id, href, data-*) untouched. Plain text is
        // escaped so a "<" or "&" in a header is a text node, not markup.
        $prepared = [];
        foreach ($texts as $key => $text) {
            $prepared[$key] = $htmlFlags[$key]
                ? (string)$text
                : htmlspecialchars((string)$text, ENT_NOQUOTES | ENT_HTML5, 'UTF-8', true);
        }

        $formData = [
            'source_lang' => strtoupper($this->normalizeLanguageCode($source)),
            'target_lang' => $this->resolveTargetCode($target),
            'preserve_formatting' => '1',
            'tag_handling' => 'html',
            'ignore_tags' => self::IGNORE_TAGS,
        ];

        $glossaryId = $this->getConfiguredValue('deeplGlossaryId');
        if ($glossaryId !== '') {
            $formData['glossary_id'] = $glossaryId;
        }

        $body = http_build_query($formData);
        foreach (array_values($prepared) as $text) {
            $body .= '&text=' . rawurlencode($text);
        }

        $response = $this->request('POST', $this->getEndpoint($apiKey), [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        $decoded = $this->decodeJson($response);
        $translations = array_column((array)($decoded['translations'] ?? []), 'text');

        $results = $this->mapResults($texts, $translations);

        // Undo the escaping for plain-text fields.
        foreach ($results as $key => $translation) {
            if (isset($htmlFlags[$key]) && !$htmlFlags[$key]) {
                $results[$key] = html_entity_decode((string)$translation, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        return $results;
    }

    /**
     * @param array<int|string, string> $texts
     * @param array<string, mixed> $options
     * @return array<int|string, bool>
     */
    protected function resolveHtmlFlags(array $texts, array $options): array
    {
        $option = $options['html'] ?? null;
        $flags = [];
        foreach ($texts as $key => $text) {
            if (is_array($option) && array_key_exists($key, $option)) {
                $flags[$key] = (bool)$option[$key];
            } elseif (is_bool($option) && $option) {
                $flags[$key] = true;
            } else {
                // No (or "false") batch flag: detect markup instead of trusting it,
                // mixed batches must not send RTE content as plain text.
                $flags[$key] = $this->containsMarkup((string)$text);
            }
        }

        return $flags;
    }

    protected function containsMarkup(string $text): bool
    {
        return (bool)preg_match('#</?[a-z][a-z0-9-]*(\s[^<>]*)?/?>#i', $text);
    }

    public function translatex(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }

        $this->currentPageId = (int)($options['pageId'] ?? 0);

        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new TranslationProviderException('DeepL API key is not configured.', 1710000070);
        }

        $formData = [
            'source_lang' => strtoupper($this->normalizeLanguageCode($source)),
            'target_lang' => $this->resolveTargetCode($target),
            'preserve_formatting' => '1',
        ];

        if (!empty($options['html'])) {
            $formData['tag_handling'] = 'html';
        }

        $glossaryId = $this->getConfiguredValue('deeplGlossaryId');
        if ($glossaryId !== '') {
            $formData['glossary_id'] = $glossaryId;
        }

        $body = http_build_query($formData);
        foreach (array_values($texts) as $text) {
            $body .= '&text=' . rawurlencode($text);
        }

        $response = $this->request('POST', $this->getEndpoint($apiKey), [
            'headers' => [
                'Authorization' => 'DeepL-Auth-Key ' . $apiKey,
                'Content-Type' => 'application/x-www-form-urlencoded',
            ],
            'body' => $body,
        ]);

        $decoded = $this->decodeJson($response);
        $translations = array_column((array)($decoded['translations'] ?? []), 'text');

        return $this->mapResults($texts, $translations);
    }

    protected function resolveTargetCode(TranslationTarget $target): string
    {
        $code = $this->normalizeLanguageCode($target);
        if (isset(self::TARGET_OVERRIDES[$code])) {
            $hreflang = strtoupper(str_replace('_', '-', $target->getHreflang()));
            if ($hreflang !== '' && strpos($hreflang, '-') !== false) {
                return $hreflang;
            }

            return self::TARGET_OVERRIDES[$code];
        }

        return strtoupper($code);
    }

    protected function getEndpoint(string $apiKey): string
    {
        $configured = $this->getConfiguredValue('deeplApiUrl');
        if ($configured !== '') {
            return $configured;
        }

        return substr($apiKey, -3) === ':fx' ? self::HOST_FREE : self::HOST_PRO;
    }

    protected function getApiKey(): string
    {
        return $this->getConfiguredValue('deeplApiKey');
    }

    /**
     * 1. site YAML
     * 2. $GLOBALS['ESET_CONF_VARS']
     * 3. Extension Configuration
     */
    protected function getConfiguredValue(string $key): string
    {
        $fromSite = $this->getValueFromResolvedSites($key);
        if ($fromSite !== '') {
            return $fromSite;
        }

        $fromEset = $this->getEsetConfValue($key);
        if ($fromEset !== '') {
            return $fromEset;
        }

        return trim($this->configuration->get($key));
    }

    protected function getEsetConfValue(string $key): string
    {
        $esetKey = self::ESET_CONF_KEY_MAP[$key] ?? $key;
        $bag = $GLOBALS['ESET_CONF_VARS'] ?? [];
        if (!is_array($bag) || !isset($bag[$esetKey])) {
            return '';
        }

        $value = $bag[$esetKey];

        return is_scalar($value) ? trim((string)$value) : '';
    }

    protected function getValueFromResolvedSites(string $key): string
    {
        foreach ($this->resolveCandidateSites() as $site) {
            $value = $this->readSiteValue($site, $key);
            if ($value !== '') {
                return $value;
            }
        }

        return '';
    }

    /**
     * Len aktuálny site z requestu alebo page uid. Žiadny getAllSites().
     *
     * @return Site[]
     */
    protected function resolveCandidateSites(): array
    {
        $candidates = [];

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (is_object($request) && method_exists($request, 'getAttribute')) {
            $site = $request->getAttribute('site');
            if ($site instanceof Site) {
                $candidates[$site->getIdentifier()] = $site;
            }
        }

        $pageId = $this->resolveCurrentPageId();
        if ($pageId > 0) {
            try {
                $site = $this->getSiteFinder()->getSiteByPageId($pageId);
                $candidates[$site->getIdentifier()] = $site;
            } catch (\Throwable $exception) {
                // page nepatrí do žiadneho site
            }
        }

        return array_values($candidates);
    }

    protected function resolveCurrentPageId(): int
    {
        if ($this->currentPageId > 0) {
            return $this->currentPageId;
        }

        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if (is_object($request) && method_exists($request, 'getAttribute')) {
            $routing = $request->getAttribute('routing');
            if (is_object($routing) && method_exists($routing, 'getPageId')) {
                $pageId = (int)$routing->getPageId();
                if ($pageId > 0) {
                    return $pageId;
                }
            }
        }

        return (int)($_GET['id'] ?? $_POST['id'] ?? 0);
    }

    protected function readSiteValue(Site $site, string $key): string
    {
        $configuration = $site->getConfiguration();
        $paths = [
            'translator.' . $key,
            'settings.translator.' . $key,
            'settings.' . $key,
            $key,
        ];

        foreach ($paths as $path) {
            $value = $this->getArrayPath($configuration, $path);
            if (!is_scalar($value)) {
                continue;
            }

            $value = trim((string)$value);
            if ($value === '' || preg_match('/%env\\([^)]+\\)%/', $value)) {
                continue;
            }

            return $value;
        }

        return '';
    }

    /**
     * @param array<string, mixed> $source
     * @return mixed
     */
    protected function getArrayPath(array $source, string $path)
    {
        $cursor = $source;
        foreach (explode('.', $path) as $segment) {
            if (!is_array($cursor) || !array_key_exists($segment, $cursor)) {
                return null;
            }
            $cursor = $cursor[$segment];
        }

        return $cursor;
    }

    protected function getSiteFinder(): SiteFinder
    {
        return GeneralUtility::makeInstance(SiteFinder::class);
    }
}