<?php

declare(strict_types=1);

namespace ESET\Translator\Format;

use ESET\Translator\Domain\Dto\TranslationDataSet;

/**
 * Contract for every translation exchange format.
 *
 * Implementations are registered as tagged services
 * ("eset_translator.translation_format") and are picked up automatically.
 */
interface FormatInterface
{
    /**
     * Unique key, used in URLs and stored on the job record.
     */
    public function getIdentifier(): string;

    /**
     * Human readable name shown in the target picker.
     */
    public function getTitle(): string;

    public function getFileExtension(): string;

    public function getContentType(): string;

    /**
     * Serialize a data set into the exchange format.
     */
    public function export(TranslationDataSet $dataSet): string;

    /**
     * Parse an exchange file back into a data set.
     *
     * The returned data set carries the translated values in the target text of
     * every unit. Implementations must not trust the file contents.
     */
    public function import(string $content): TranslationDataSet;

    /**
     * Cheap sniff so uploads can be routed to the right parser.
     */
    public function canImport(string $content, string $fileName): bool;
}
