<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Dto;

/**
 * The complete payload that travels between TYPO3 and a translation
 * provider / translation agency.
 *
 * @implements \IteratorAggregate<int, TranslationUnit>
 */
final class TranslationDataSet implements \IteratorAggregate, \Countable, \JsonSerializable
{
    /** @var TranslationTarget */
    private $source;

    /** @var TranslationTarget */
    private $target;

    /** @var int */
    private $pageUid;

    /** @var TranslationUnit[] */
    private $units = [];

    /** @var string */
    private $jobIdentifier = '';

    /** @var string */
    private $title = '';

    public function __construct(TranslationTarget $source, TranslationTarget $target, int $pageUid = 0)
    {
        $this->source = $source;
        $this->target = $target;
        $this->pageUid = $pageUid;
    }

    public function addUnit(TranslationUnit $unit): void
    {
        $this->units[$unit->getId()] = $unit;
    }

    public function getUnit(string $id): ?TranslationUnit
    {
        return $this->units[$id] ?? null;
    }

    /**
     * @return TranslationUnit[]
     */
    public function getUnits(): array
    {
        return array_values($this->units);
    }

    /**
     * Units grouped by "table/uid" so importers can write one DataHandler
     * record per group instead of one per field.
     *
     * @return array<string, TranslationUnit[]>
     */
    public function getUnitsGroupedByRecord(): array
    {
        $grouped = [];
        foreach ($this->units as $unit) {
            $grouped[$unit->getTable() . '/' . $unit->getUid()][] = $unit;
        }

        return $grouped;
    }

    public function getSource(): TranslationTarget
    {
        return $this->source;
    }

    public function getTarget(): TranslationTarget
    {
        return $this->target;
    }

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    public function setPageUid(int $pageUid): void
    {
        $this->pageUid = $pageUid;
    }

    public function getJobIdentifier(): string
    {
        return $this->jobIdentifier;
    }

    public function setJobIdentifier(string $jobIdentifier): void
    {
        $this->jobIdentifier = $jobIdentifier;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function setTitle(string $title): void
    {
        $this->title = $title;
    }

    public function countTranslated(): int
    {
        $count = 0;
        foreach ($this->units as $unit) {
            if ($unit->isTranslated()) {
                $count++;
            }
        }

        return $count;
    }

    public function count(): int
    {
        return count($this->units);
    }

    /**
     * @return \ArrayIterator<int, TranslationUnit>
     */
    public function getIterator(): \ArrayIterator
    {
        return new \ArrayIterator(array_values($this->units));
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'job' => $this->jobIdentifier,
            'title' => $this->title,
            'pageUid' => $this->pageUid,
            'source' => $this->source,
            'target' => $this->target,
            'units' => $this->getUnits(),
        ];
    }
}
