<?php

declare(strict_types=1);

namespace ESET\Translator\Provider;

use ESET\Translator\Domain\Dto\TranslationTarget;

/**
 * Contract for automated translation providers.
 *
 * Implementations are registered as tagged services
 * ("eset_translator.translation_provider"); adding a new provider therefore
 * requires nothing but a class plus a service tag.
 */
interface TranslationProviderInterface
{
    public function getIdentifier(): string;

    public function getTitle(): string;

    /**
     * False when required credentials/endpoints are missing. The UI uses this
     * to fall back to the manual file download instead of failing.
     */
    public function isAvailable(): bool;

    public function supports(TranslationTarget $source, TranslationTarget $target): bool;

    /**
     * Translates a batch of strings.
     *
     * @param string[] $texts indexed by an arbitrary key
     * @param array{html?: bool} $options
     * @return string[] translations, keyed exactly like $texts
     *
     * @throws TranslationProviderException
     */
    public function translate(array $texts, TranslationTarget $source, TranslationTarget $target, array $options = []): array;

    /**
     * Maximum number of strings per request; the job runner chunks accordingly.
     */
    public function getBatchSize(): int;
}
