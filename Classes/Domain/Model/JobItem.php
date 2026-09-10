<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Model;

use ESET\Translator\Domain\Dto\TranslationUnit;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;

/**
 * One translatable field inside a job. Kept as a record so the module can show
 * per field progress and so a partially failed job can be resumed.
 */
class JobItem extends AbstractEntity
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_FAILED = 'failed';

    /** @var Job|null */
    protected $job;

    /** @var string */
    protected $tableName = '';

    /** @var int */
    protected $recordUid = 0;

    /** @var string */
    protected $fieldName = '';

    /** @var int */
    protected $recordPageUid = 0;

    /** @var int uid of the localized record the value was written to */
    protected $targetUid = 0;

    /** @var string */
    protected $sourceText = '';

    /** @var string */
    protected $targetText = '';

    /** @var string */
    protected $sourceHash = '';

    /** @var bool */
    protected $html = false;

    /** @var string */
    protected $status = self::STATUS_PENDING;

    /** @var string */
    protected $errorMessage = '';

    public static function fromUnit(TranslationUnit $unit): self
    {
        $item = new self();
        $item->setTableName($unit->getTable());
        $item->setRecordUid($unit->getUid());
        $item->setFieldName($unit->getField());
        $item->setRecordPageUid($unit->getPageUid());
        $item->setSourceText($unit->getSourceText());
        $item->setTargetText($unit->getTargetText());
        $item->setSourceHash($unit->getSourceHash());
        $item->setHtml($unit->isHtml());
        $item->setTargetUid($unit->getTargetUid());

        return $item;
    }

    public function toUnit(): TranslationUnit
    {
        $unit = new TranslationUnit(
            $this->tableName,
            $this->recordUid,
            $this->fieldName,
            $this->sourceText,
            $this->html,
            '',
            $this->recordPageUid
        );
        $unit->setTargetText($this->targetText);
        $unit->setTargetUid($this->targetUid);

        return $unit;
    }

    public function getJob(): ?Job
    {
        return $this->job;
    }

    public function setJob(?Job $job): void
    {
        $this->job = $job;
    }

    public function getTableName(): string
    {
        return $this->tableName;
    }

    public function setTableName(string $tableName): void
    {
        $this->tableName = $tableName;
    }

    public function getRecordUid(): int
    {
        return $this->recordUid;
    }

    public function setRecordUid(int $recordUid): void
    {
        $this->recordUid = $recordUid;
    }

    public function getFieldName(): string
    {
        return $this->fieldName;
    }

    public function setFieldName(string $fieldName): void
    {
        $this->fieldName = $fieldName;
    }

    public function getRecordPageUid(): int
    {
        return $this->recordPageUid;
    }

    public function setRecordPageUid(int $recordPageUid): void
    {
        $this->recordPageUid = $recordPageUid;
    }

    public function getTargetUid(): int
    {
        return $this->targetUid;
    }

    public function setTargetUid(int $targetUid): void
    {
        $this->targetUid = $targetUid;
    }

    public function getSourceText(): string
    {
        return $this->sourceText;
    }

    public function setSourceText(string $sourceText): void
    {
        $this->sourceText = $sourceText;
    }

    public function getTargetText(): string
    {
        return $this->targetText;
    }

    public function setTargetText(string $targetText): void
    {
        $this->targetText = $targetText;
    }

    public function getSourceHash(): string
    {
        return $this->sourceHash;
    }

    public function setSourceHash(string $sourceHash): void
    {
        $this->sourceHash = $sourceHash;
    }

    public function isHtml(): bool
    {
        return $this->html;
    }

    public function setHtml(bool $html): void
    {
        $this->html = $html;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }
}
