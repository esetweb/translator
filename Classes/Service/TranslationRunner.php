<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Model\JobItem;
use ESET\Translator\Provider\ProviderRegistry;
use ESET\Translator\Provider\TranslationProviderException;
use TYPO3\CMS\Core\Log\LogManager;
use Psr\Log\LoggerInterface;

/**
 * Runs an automated job: sends every pending item to the configured provider
 * and writes the results back into the page tree.
 */
class TranslationRunner
{
    /** @var ProviderRegistry */
    protected $providerRegistry;

    /** @var JobService */
    protected $jobService;

    /** @var ImportService */
    protected $importService;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var LoggerInterface */
    protected $logger;

    public function __construct(
        ProviderRegistry $providerRegistry,
        JobService $jobService,
        ImportService $importService,
        SiteLanguageService $siteLanguageService,
        LogManager $logManager
    ) {
        $this->providerRegistry = $providerRegistry;
        $this->jobService = $jobService;
        $this->importService = $importService;
        $this->siteLanguageService = $siteLanguageService;
        $this->logger = $logManager->getLogger(__CLASS__);
    }

    /**
     * Translates all pending items and imports the result.
     */
    public function run(Job $job, bool $autoImport = true): Job
    {
        if (!$job->isAutomated()) {
            throw new \RuntimeException('Only automated jobs can be processed by the runner.', 1710000140);
        }

        $job->setStatus(Job::STATUS_RUNNING);
        $job->setStartedAt(new \DateTime());
        $job->setErrorMessage('');
        $this->jobService->update($job);

        try {
            $this->translate($job);
            if ($autoImport) {
                $this->import($job);
            } else {
                $job->setStatus(Job::STATUS_TRANSLATED);
            }
            $job->setFinishedAt(new \DateTime());
            $this->jobService->update($job);
        } catch (\Throwable $exception) {
            $this->logger->error('Translation job failed', [
                'job' => $job->getJobIdentifier(),
                'exception' => $exception->getMessage(),
            ]);
            $this->jobService->markFailed($job, $exception->getMessage());
        }

        return $job;
    }

    protected function translate(Job $job): void
    {
        $source = $this->siteLanguageService->getTarget($job->getSourceKey());
        $target = $this->siteLanguageService->getTarget($job->getTargetKey());
        $provider = $this->providerRegistry->get($job->getProvider());

        if (!$provider->isAvailable()) {
            throw new TranslationProviderException(
                sprintf('Provider "%s" is not configured (missing API key or endpoint).', $provider->getTitle()),
                1710000141
            );
        }
        if (!$provider->supports($source, $target)) {
            throw new TranslationProviderException(
                sprintf(
                    'Provider "%s" does not support %s → %s.',
                    $provider->getTitle(),
                    $source->getTranslationCode(),
                    $target->getTranslationCode()
                ),
                1710000142
            );
        }

        /** @var JobItem[] $pending */
        $pending = [];
        foreach ($job->getItems() as $item) {
            if ($item->getStatus() === JobItem::STATUS_PENDING && trim($item->getSourceText()) !== '') {
                $pending[] = $item;
            }
        }

        // HTML and plain text need different provider options, so they are
        // translated in separate batches.
        foreach ([true, false] as $isHtml) {
            $subset = array_values(array_filter(
                $pending,
                static function (JobItem $item) use ($isHtml): bool {
                    return $item->isHtml() === $isHtml;
                }
            ));

            foreach (array_chunk($subset, max(1, $provider->getBatchSize()), true) as $chunk) {
                $texts = [];
                foreach ($chunk as $index => $item) {
                    $texts[$index] = $item->getSourceText();
                }
                $translations = $provider->translate($texts, $source, $target, ['html' => $isHtml]);

                foreach ($chunk as $index => $item) {
                    $translation = $translations[$index] ?? '';
                    if (trim($translation) === '') {
                        $item->setStatus(JobItem::STATUS_FAILED);
                        $item->setErrorMessage('Provider returned an empty translation.');
                        continue;
                    }
                    $item->setTargetText($translation);
                    $item->setStatus(JobItem::STATUS_TRANSLATED);
                }

                $job->setTranslatedCount($this->countByStatus($job, [JobItem::STATUS_TRANSLATED, JobItem::STATUS_IMPORTED]));
                $this->jobService->update($job);
            }
        }

        $job->setStatus(Job::STATUS_TRANSLATED);
        $this->jobService->update($job);
    }

    protected function import(Job $job): void
    {
        $dataSet = $this->jobService->buildDataSet($job);
        $result = $this->importService->import($dataSet);

        foreach ($job->getItems() as $item) {
            if ($item->getStatus() === JobItem::STATUS_TRANSLATED) {
                $item->setStatus(JobItem::STATUS_IMPORTED);
            }
        }

        $job->setImportedCount($result->getImported());
        $job->setStatus($result->hasErrors() ? Job::STATUS_FAILED : Job::STATUS_IMPORTED);
        if ($result->hasErrors()) {
            $job->setErrorMessage(implode(' | ', $result->getErrors()));
        }
    }

    /**
     * @param string[] $statuses
     */
    protected function countByStatus(Job $job, array $statuses): int
    {
        $count = 0;
        foreach ($job->getItems() as $item) {
            if (in_array($item->getStatus(), $statuses, true)) {
                $count++;
            }
        }

        return $count;
    }
}
