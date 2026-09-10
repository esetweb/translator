<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

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
     * DeepL only accepts a limited set of target codes; regional variants must
     * be explicit for these.
     *
     * @var array<string, string>
     */
    protected const TARGET_OVERRIDES = [
        'en' => 'EN-GB',
        'pt' => 'PT-PT',
    ];

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
        // DeepL accepts up to 50 "text" parameters per request.
        return min(50, parent::getBatchSize());
    }

    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new TranslationProviderException('DeepL API key is not configured.', 1710000070);
        }

        $formData = [
            'source_lang' => strtoupper($this->normalizeLanguageCode($source)),
            'target_lang' => $this->resolveTargetCode($target),
            'tag_handling' => !empty($options['html']) ? 'html' : 'xml',
            'preserve_formatting' => '1',
        ];
        $glossaryId = $this->configuration->get('deeplGlossaryId');
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
        $configured = $this->configuration->get('deeplApiUrl');
        if ($configured !== '') {
            return $configured;
        }

        return substr($apiKey, -3) === ':fx' ? self::HOST_FREE : self::HOST_PRO;
    }

    protected function getApiKey(): string
    {
        return trim($this->configuration->get('deeplApiKey'));
    }
}
