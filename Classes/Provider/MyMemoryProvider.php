<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

/**
 * MyMemory – free translation memory API, no key required (rate limited).
 *
 * Meant for smoke testing the whole pipeline without any paid account. It only
 * accepts one string per request, so the batch size is 1 on purpose.
 */
class MyMemoryProvider extends AbstractTranslationProvider
{
    public const IDENTIFIER = 'mymemory';

    protected const ENDPOINT = 'https://api.mymemory.translated.net/get';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'MyMemory (free, rate limited – for testing)';
    }

    public function isAvailable(): bool
    {
        return $this->configuration->getBool('enableMyMemory', false);
    }

    public function getBatchSize(): int
    {
        return 1;
    }

    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        $result = [];
        $languagePair = $this->normalizeLanguageCode($source) . '|' . $this->normalizeLanguageCode($target);
        $email = trim($this->configuration->get('myMemoryEmail'));

        foreach ($texts as $key => $text) {
            if (mb_strlen($text) > 500) {
                throw new TranslationProviderException(
                    'MyMemory only accepts segments up to 500 characters. Use DeepL, Google or the manual export for this content.',
                    1710000100
                );
            }
            $query = [
                'q' => $text,
                'langpair' => $languagePair,
            ];
            if ($email !== '') {
                $query['de'] = $email;
            }

            $response = $this->request('GET', self::ENDPOINT . '?' . http_build_query($query));
            $decoded = $this->decodeJson($response);
            $translation = (string)($decoded['responseData']['translatedText'] ?? '');
            if ($translation === '') {
                throw new TranslationProviderException(
                    'MyMemory returned an empty translation: ' . $this->truncate((string)($decoded['responseDetails'] ?? '')),
                    1710000101
                );
            }
            $result[$key] = $translation;
        }

        return $result;
    }
}
