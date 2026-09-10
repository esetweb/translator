<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Model\JobItem;
use ESET\Translator\Domain\Repository\JobRepository;
use ESET\Translator\Format\FormatRegistry;
use ESET\Translator\Provider\ProviderRegistry;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Creates and updates translation jobs.
 */
class JobService
{
    /** @var JobRepository */
    protected $jobRepository;

    /** @var PersistenceManagerInterface */
    protected $persistenceManager;

    /** @var RecordCollectorService */
    protected $recordCollector;

    /** @var PermissionService */
    protected $permissionService;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var ProviderRegistry */
    protected $providerRegistry;

    /** @var FormatRegistry */
    protected $formatRegistry;

    /** @var ConfigurationService */
    protected $configuration;

    public function __construct(
        JobRepository $jobRepository,
        PersistenceManagerInterface $persistenceManager,
        RecordCollectorService $recordCollector,
        PermissionService $permissionService,
        SiteLanguageService $siteLanguageService,
        ProviderRegistry $providerRegistry,
        FormatRegistry $formatRegistry,
        ConfigurationService $configuration
    ) {
        $this->jobRepository = $jobRepository;
        $this->persistenceManager = $persistenceManager;
        $this->recordCollector = $recordCollector;
        $this->permissionService = $permissionService;
        $this->siteLanguageService = $siteLanguageService;
        $this->providerRegistry = $providerRegistry;
        $this->formatRegistry = $formatRegistry;
        $this->configuration = $configuration;
    }

    /**
     * @param array{mode?: string, provider?: string, format?: string, depth?: int, title?: string, onlyUntranslated?: bool, skipCTypes?: string[]} $options
     */
    public function createJob(int $pageUid, string $sourceKey, string $targetKey, array $options = []): Job
    {
        $source = $this->siteLanguageService->getTarget($sourceKey);
        $target = $this->siteLanguageService->getTarget($targetKey);
        $this->permissionService->assertTranslationAllowed($pageUid, $source, $target);

        $mode = ($options['mode'] ?? Job::MODE_MANUAL) === Job::MODE_AUTOMATED
            ? Job::MODE_AUTOMATED
            : Job::MODE_MANUAL;
        $depth = max(0, (int)($options['depth'] ?? 0));
        $onlyUntranslated = (bool)($options['onlyUntranslated'] ?? true);
        $skipCTypes = array_values(array_filter((array)($options['skipCTypes'] ?? [])));

        $dataSet = $this->recordCollector->collect($pageUid, $source, $target, $depth, $onlyUntranslated, $skipCTypes);
        if (count($dataSet) === 0) {
            throw new \RuntimeException(
                'Nothing to translate: no translatable content was found for the selected page and language.',
                1710000120
            );
        }

        $job = new Job();
        $job->setTitle($options['title'] ?? $dataSet->getTitle());
        $job->setPageUid($pageUid);
        $job->setDepth($depth);
        $job->setSourceTarget($source);
        $job->setTargetTarget($target);
        $job->setMode($mode);
        $job->setFormat($this->resolveFormat($options['format'] ?? ''));
        $job->setProvider($mode === Job::MODE_AUTOMATED ? $this->resolveProvider($options['provider'] ?? '', $source, $target) : '');
        $job->setStatus($mode === Job::MODE_AUTOMATED ? Job::STATUS_QUEUED : Job::STATUS_NEW);
        $job->setUnitCount(count($dataSet));
        $job->setBackendUserId((int)($GLOBALS['BE_USER']->user['uid'] ?? 0));

        foreach ($dataSet as $unit) {
            $item = JobItem::fromUnit($unit);
            $item->setJob($job);
            $job->addItem($item);
        }

        $this->jobRepository->add($job);
        $this->persistenceManager->persistAll();

        return $job;
    }

    /**
     * Rebuilds the data set of a job from its stored items, so an export can be
     * regenerated at any time without re-reading the page tree.
     */
    public function buildDataSet(Job $job): TranslationDataSet
    {
        $source = $this->siteLanguageService->findTarget($job->getSourceKey())
            ?? TranslationTarget::fromKey($job->getSourceKey());
        $target = $this->siteLanguageService->findTarget($job->getTargetKey())
            ?? TranslationTarget::fromKey($job->getTargetKey());

        $dataSet = new TranslationDataSet($source, $target, $job->getPageUid());
        $dataSet->setJobIdentifier($job->getJobIdentifier());
        $dataSet->setTitle($job->getTitle());

        foreach ($job->getItems() as $item) {
            $dataSet->addUnit($item->toUnit());
        }

        return $dataSet;
    }

    public function markFailed(Job $job, string $message): void
    {
        $job->setStatus(Job::STATUS_FAILED);
        $job->setErrorMessage($message);
        $job->setFinishedAt(new \DateTime());
        $this->update($job);
    }

    public function update(Job $job): void
    {
        $this->jobRepository->update($job);
        $this->persistenceManager->persistAll();
    }

    protected function resolveFormat(string $requested): string
    {
        if ($requested !== '' && $this->formatRegistry->has($requested)) {
            return $requested;
        }
        $default = $this->configuration->getDefaultFormat();

        return $this->formatRegistry->has($default) ? $default : array_key_first($this->formatRegistry->getAll());
    }

    protected function resolveProvider(string $requested, TranslationTarget $source, TranslationTarget $target): string
    {
        $available = $this->providerRegistry->getAvailableFor($source, $target);
        if ($requested !== '' && isset($available[$requested])) {
            return $requested;
        }
        $default = $this->providerRegistry->resolveDefault($source, $target);
        if ($default === null) {
            throw new \RuntimeException(
                'No automated translation provider is configured for this language pair. Use the manual export instead.',
                1710000121
            );
        }

        return $default->getIdentifier();
    }
}
