<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Dto;

/**
 * Outcome of an import run, shown as a flash message / CLI summary.
 */
final class ImportResult
{
    /** @var int */
    private $imported = 0;

    /** @var int */
    private $skipped = 0;

    /** @var int */
    private $createdRecords = 0;

    /** @var string[] */
    private $errors = [];

    public function countImported(int $amount = 1): void
    {
        $this->imported += $amount;
    }

    public function countSkipped(int $amount = 1): void
    {
        $this->skipped += $amount;
    }

    public function countCreatedRecord(int $amount = 1): void
    {
        $this->createdRecords += $amount;
    }

    public function addError(string $message): void
    {
        $this->errors[] = $message;
    }

    public function getImported(): int
    {
        return $this->imported;
    }

    public function getSkipped(): int
    {
        return $this->skipped;
    }

    public function getCreatedRecords(): int
    {
        return $this->createdRecords;
    }

    /**
     * @return string[]
     */
    public function getErrors(): array
    {
        return $this->errors;
    }

    public function hasErrors(): bool
    {
        return $this->errors !== [];
    }

    public function getSummary(): string
    {
        $summary = sprintf(
            '%d field(s) imported, %d skipped, %d localized record(s) created.',
            $this->imported,
            $this->skipped,
            $this->createdRecords
        );
        if ($this->hasErrors()) {
            $summary .= ' Errors: ' . implode(' | ', $this->errors);
        }

        return $summary;
    }
}
