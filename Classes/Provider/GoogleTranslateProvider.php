<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

/**
 * Google Cloud Translation API v2 (API key based).
 */
class GoogleTranslateProvider extends AbstractTranslationProvider
{
    public const IDENTIFIER = 'google';

    protected const ENDPOINT = 'https://translation.googleapis.com/language/translate/v2';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'Google Cloud Translation';
    }

    public function isAvailable(): bool
    {
        return $this->getApiKey() !== '';
    }

    public function getBatchSize(): int
    {
        return min(100, parent::getBatchSize());
    }

    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }
        $apiKey = $this->getApiKey();
        if ($apiKey === '') {
            throw new TranslationProviderException('Google Translation API key is not configured.', 1710000080);
        }

        $payload = [
            'q' => array_values($texts),
            'source' => $this->normalizeLanguageCode($source),
            'target' => $this->normalizeLanguageCode($target),
            'format' => !empty($options['html']) ? 'html' : 'text',
        ];

        $response = $this->request('POST', self::ENDPOINT . '?key=' . rawurlencode($apiKey), [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $decoded = $this->decodeJson($response);
        $translations = array_column((array)($decoded['data']['translations'] ?? []), 'translatedText');
        $translations = array_map(
            static function ($value): string {
                return html_entity_decode((string)$value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            },
            $translations
        );

        return $this->mapResults($texts, $translations);
    }

    protected function getApiKey(): string
    {
        return trim($this->configuration->get('googleApiKey'));
    }
}
