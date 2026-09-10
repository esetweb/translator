<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

/**
 * LibreTranslate – free and self hostable (docker run libretranslate/libretranslate).
 *
 * Ideal for local development and CI because no credentials leave the machine.
 */
class LibreTranslateProvider extends AbstractTranslationProvider
{
    public const IDENTIFIER = 'libretranslate';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'LibreTranslate (free / self hosted)';
    }

    public function isAvailable(): bool
    {
        return $this->getBaseUrl() !== '';
    }

    public function getBatchSize(): int
    {
        return min(25, parent::getBatchSize());
    }

    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        if ($texts === []) {
            return [];
        }
        $baseUrl = $this->getBaseUrl();
        if ($baseUrl === '') {
            throw new TranslationProviderException('LibreTranslate URL is not configured.', 1710000090);
        }

        $payload = [
            'q' => array_values($texts),
            'source' => $this->normalizeLanguageCode($source),
            'target' => $this->normalizeLanguageCode($target),
            'format' => !empty($options['html']) ? 'html' : 'text',
        ];
        $apiKey = trim($this->configuration->get('libreTranslateApiKey'));
        if ($apiKey !== '') {
            $payload['api_key'] = $apiKey;
        }

        $response = $this->request('POST', rtrim($baseUrl, '/') . '/translate', [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => (string)json_encode($payload, JSON_UNESCAPED_UNICODE),
        ]);

        $decoded = $this->decodeJson($response);
        $translated = $decoded['translatedText'] ?? [];
        if (is_string($translated)) {
            $translated = [$translated];
        }

        return $this->mapResults($texts, (array)$translated);
    }

    protected function getBaseUrl(): string
    {
        return trim($this->configuration->get('libreTranslateUrl'));
    }
}
