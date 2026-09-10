<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

/**
 * Does not translate anything, it only prefixes the source with the target
 * language code. Used to exercise the full job pipeline in development and in
 * functional tests without hitting an external API.
 */
class EchoProvider extends AbstractTranslationProvider
{
    public const IDENTIFIER = 'echo';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'Echo (development only, no real translation)';
    }

    public function isAvailable(): bool
    {
        return $this->configuration->getBool('enableEchoProvider', false);
    }

    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array
    {
        $prefix = '[' . strtoupper($target->getTranslationCode()) . '] ';
        $result = [];
        foreach ($texts as $key => $text) {
            $result[$key] = $prefix . $text;
        }

        return $result;
    }
}
