<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Model;

use ESET\Translator\Domain\Dto\TranslationTarget;
use TYPO3\CMS\Extbase\DomainObject\AbstractEntity;
use TYPO3\CMS\Extbase\Persistence\ObjectStorage;

/**
 * One translation request: "translate page X from (site,lang) A into (site,lang) B".
 */
class Job extends AbstractEntity
{
    public const STATUS_NEW = 'new';
    public const STATUS_EXPORTED = 'exported';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_TRANSLATED = 'translated';
    public const STATUS_IMPORTED = 'imported';
    public const STATUS_FAILED = 'failed';
    public const STATUS_CANCELLED = 'cancelled';

    public const MODE_AUTOMATED = 'automated';
    public const MODE_MANUAL = 'manual';

    /** @var string */
    protected $jobIdentifier = '';

    /** @var string */
    protected $title = '';

    /** @var int */
    protected $pageUid = 0;

    /** @var int */
    protected $depth = 0;

    /** @var string Site identifier of the source */
    protected $sourceSite = '';

    /** @var int */
    protected $sourceLanguageId = 0;

    /** @var string Site identifier of the target */
    protected $targetSite = '';

    /** @var int */
    protected $targetLanguageId = 0;

    /** @var string automated|manual */
    protected $mode = self::MODE_MANUAL;

    /** @var string */
    protected $provider = '';

    /** @var string */
    protected $format = '';

    /** @var string */
    protected $status = self::STATUS_NEW;

    /** @var int */
    protected $unitCount = 0;

    /** @var int */
    protected $translatedCount = 0;

    /** @var int */
    protected $importedCount = 0;

    /** @var string */
    protected $errorMessage = '';

    /** @var string Relative path of the exported file */
    protected $exportFile = '';

    /** @var string Relative path of the last imported file */
    protected $importFile = '';

    /** @var int */
    protected $backendUserId = 0;

    /** @var \DateTime|null */
    protected $startedAt;

    /** @var \DateTime|null */
    protected $finishedAt;

    /** @var ObjectStorage<JobItem> */
    protected $items;

    public function __construct()
    {
        $this->items = new ObjectStorage();
        $this->jobIdentifier = self::generateIdentifier();
    }

    public static function generateIdentifier(): string
    {
        return date('Ymd-His') . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
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

    public function getPageUid(): int
    {
        return $this->pageUid;
    }

    public function setPageUid(int $pageUid): void
    {
        $this->pageUid = $pageUid;
    }

    public function getDepth(): int
    {
        return $this->depth;
    }

    public function setDepth(int $depth): void
    {
        $this->depth = $depth;
    }

    public function getSourceSite(): string
    {
        return $this->sourceSite;
    }

    public function setSourceSite(string $sourceSite): void
    {
        $this->sourceSite = $sourceSite;
    }

    public function getSourceLanguageId(): int
    {
        return $this->sourceLanguageId;
    }

    public function setSourceLanguageId(int $sourceLanguageId): void
    {
        $this->sourceLanguageId = $sourceLanguageId;
    }

    public function getTargetSite(): string
    {
        return $this->targetSite;
    }

    public function setTargetSite(string $targetSite): void
    {
        $this->targetSite = $targetSite;
    }

    public function getTargetLanguageId(): int
    {
        return $this->targetLanguageId;
    }

    public function setTargetLanguageId(int $targetLanguageId): void
    {
        $this->targetLanguageId = $targetLanguageId;
    }

    public function getSourceKey(): string
    {
        return $this->sourceSite . TranslationTarget::KEY_SEPARATOR . $this->sourceLanguageId;
    }

    public function getTargetKey(): string
    {
        return $this->targetSite . TranslationTarget::KEY_SEPARATOR . $this->targetLanguageId;
    }

    public function setSourceTarget(TranslationTarget $target): void
    {
        $this->sourceSite = $target->getSiteIdentifier();
        $this->sourceLanguageId = $target->getLanguageId();
    }

    public function setTargetTarget(TranslationTarget $target): void
    {
        $this->targetSite = $target->getSiteIdentifier();
        $this->targetLanguageId = $target->getLanguageId();
    }

    public function getMode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function isAutomated(): bool
    {
        return $this->mode === self::MODE_AUTOMATED;
    }

    public function getProvider(): string
    {
        return $this->provider;
    }

    public function setProvider(string $provider): void
    {
        $this->provider = $provider;
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function isFinished(): bool
    {
        return in_array($this->status, [self::STATUS_IMPORTED, self::STATUS_FAILED, self::STATUS_CANCELLED], true);
    }

    public function isProcessable(): bool
    {
        return in_array($this->status, [self::STATUS_NEW, self::STATUS_QUEUED], true);
    }

    public function getUnitCount(): int
    {
        return $this->unitCount;
    }

    public function setUnitCount(int $unitCount): void
    {
        $this->unitCount = $unitCount;
    }

    public function getTranslatedCount(): int
    {
        return $this->translatedCount;
    }

    public function setTranslatedCount(int $translatedCount): void
    {
        $this->translatedCount = $translatedCount;
    }

    public function getImportedCount(): int
    {
        return $this->importedCount;
    }

    public function setImportedCount(int $importedCount): void
    {
        $this->importedCount = $importedCount;
    }

    /**
     * Progress in percent, used by the module's progress bars.
     */
    public function getProgress(): int
    {
        if ($this->unitCount === 0) {
            return $this->isFinished() ? 100 : 0;
        }

        return (int)round(min($this->translatedCount, $this->unitCount) / $this->unitCount * 100);
    }

    public function getErrorMessage(): string
    {
        return $this->errorMessage;
    }

    public function setErrorMessage(string $errorMessage): void
    {
        $this->errorMessage = $errorMessage;
    }

    public function getExportFile(): string
    {
        return $this->exportFile;
    }

    public function setExportFile(string $exportFile): void
    {
        $this->exportFile = $exportFile;
    }

    public function getImportFile(): string
    {
        return $this->importFile;
    }

    public function setImportFile(string $importFile): void
    {
        $this->importFile = $importFile;
    }

    public function getBackendUserId(): int
    {
        return $this->backendUserId;
    }

    public function setBackendUserId(int $backendUserId): void
    {
        $this->backendUserId = $backendUserId;
    }

    public function getStartedAt(): ?\DateTime
    {
        return $this->startedAt;
    }

    public function setStartedAt(?\DateTime $startedAt): void
    {
        $this->startedAt = $startedAt;
    }

    public function getFinishedAt(): ?\DateTime
    {
        return $this->finishedAt;
    }

    public function setFinishedAt(?\DateTime $finishedAt): void
    {
        $this->finishedAt = $finishedAt;
    }

    /**
     * @return ObjectStorage<JobItem>
     */
    public function getItems(): ObjectStorage
    {
        return $this->items;
    }

    /**
     * @param ObjectStorage<JobItem> $items
     */
    public function setItems(ObjectStorage $items): void
    {
        $this->items = $items;
    }

    public function addItem(JobItem $item): void
    {
        $this->items->attach($item);
    }
}
