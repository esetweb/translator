<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Format\FormatRegistry;

/**
 * Serializes a job / data set into a downloadable translation file.
 */
class ExportService
{
    /** @var FormatRegistry */
    protected $formatRegistry;

    /** @var ConfigurationService */
    protected $configuration;

    /** @var JobService */
    protected $jobService;

    public function __construct(
        FormatRegistry $formatRegistry,
        ConfigurationService $configuration,
        JobService $jobService
    ) {
        $this->formatRegistry = $formatRegistry;
        $this->configuration = $configuration;
        $this->jobService = $jobService;
    }

    /**
     * @return array{fileName: string, content: string, contentType: string}
     */
    public function exportJob(Job $job): array
    {
        $format = $this->formatRegistry->get($job->getFormat());
        $dataSet = $this->jobService->buildDataSet($job);
        $content = $format->export($dataSet);

        $fileName = sprintf(
            'eset-translation_%s_%s-to-%s.%s',
            $job->getJobIdentifier(),
            $this->sanitize($job->getSourceKey()),
            $this->sanitize($job->getTargetKey()),
            $format->getFileExtension()
        );

        $this->persist($job, $fileName, $content);

        if ($job->getStatus() === Job::STATUS_NEW) {
            $job->setStatus(Job::STATUS_EXPORTED);
            $this->jobService->update($job);
        }

        return [
            'fileName' => $fileName,
            'content' => $content,
            'contentType' => $format->getContentType(),
        ];
    }

    /**
     * @return array{fileName: string, content: string, contentType: string}
     */
    public function exportDataSet(TranslationDataSet $dataSet, string $formatIdentifier): array
    {
        $format = $this->formatRegistry->get($formatIdentifier);

        return [
            'fileName' => sprintf(
                'eset-translation_%s-to-%s.%s',
                $this->sanitize($dataSet->getSource()->getKey()),
                $this->sanitize($dataSet->getTarget()->getKey()),
                $format->getFileExtension()
            ),
            'content' => $format->export($dataSet),
            'contentType' => $format->getContentType(),
        ];
    }

    protected function persist(Job $job, string $fileName, string $content): void
    {
        $path = $this->configuration->getStorageFolder() . '/' . $fileName;
        if (@file_put_contents($path, $content) !== false) {
            $job->setExportFile($fileName);
            $this->jobService->update($job);
        }
    }

    protected function sanitize(string $value): string
    {
        return preg_replace('/[^A-Za-z0-9._-]/', '-', $value) ?? 'unknown';
    }
}
