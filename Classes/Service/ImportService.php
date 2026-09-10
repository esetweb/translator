<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\ImportResult;
use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Domain\Dto\TranslationUnit;
use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Format\FormatRegistry;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Writes translated values back into TYPO3 via DataHandler.
 *
 * Everything goes through DataHandler so workspaces, history, reference index
 * and hooks behave exactly like a manual translation in the backend.
 */
class ImportService
{
    /** @var FormatRegistry */
    protected $formatRegistry;

    /** @var RecordCollectorService */
    protected $recordCollector;

    /** @var PermissionService */
    protected $permissionService;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var ConfigurationService */
    protected $configuration;

    /** @var ConnectionPool */
    protected $connectionPool;

    public function __construct(
        FormatRegistry $formatRegistry,
        RecordCollectorService $recordCollector,
        PermissionService $permissionService,
        SiteLanguageService $siteLanguageService,
        ConfigurationService $configuration,
        ConnectionPool $connectionPool
    ) {
        $this->formatRegistry = $formatRegistry;
        $this->recordCollector = $recordCollector;
        $this->permissionService = $permissionService;
        $this->siteLanguageService = $siteLanguageService;
        $this->configuration = $configuration;
        $this->connectionPool = $connectionPool;
    }

    /**
     * Imports an uploaded translation file.
     *
     * $job is used to recover source/target when the file does not carry them
     * (e.g. an old l10nmgr CATXML file).
     */
    public function importFile(string $content, string $fileName, ?Job $job = null): ImportResult
    {
        $format = $this->formatRegistry->detect($content, $fileName);
        $dataSet = $format->import($content);

        if ($job !== null) {
            $dataSet = $this->mergeWithJob($dataSet, $job);
        }

        return $this->import($dataSet);
    }

    public function import(TranslationDataSet $dataSet): ImportResult
    {
        $result = new ImportResult();
        $source = $this->resolveTarget($dataSet->getSource());
        $target = $this->resolveTarget($dataSet->getTarget());

        $this->permissionService->assertTranslationAllowed($dataSet->getPageUid(), $source, $target);
        $this->assertPageBelongsToTargetSite($dataSet->getPageUid(), $target);

        $groups = $dataSet->getUnitsGroupedByRecord();
        // Pages first: a translated content element must be able to reference
        // an already existing page translation.
        uksort($groups, static function (string $a, string $b): int {
            return (int)(strpos($b, 'pages/') === 0) <=> (int)(strpos($a, 'pages/') === 0);
        });

        foreach ($groups as $recordKey => $units) {
            [$table, $uid] = explode('/', $recordKey);
            try {
                $this->importRecord($table, (int)$uid, $units, $target, $result);
            } catch (\Throwable $exception) {
                $result->addError(sprintf('%s:%s – %s', $table, $uid, $exception->getMessage()));
            }
        }

        return $result;
    }

    /**
     * @param TranslationUnit[] $units
     */
    protected function importRecord(
        string $table,
        int $uid,
        array $units,
        TranslationTarget $target,
        ImportResult $result
    ): void {
        if (!isset($GLOBALS['TCA'][$table])) {
            throw new \RuntimeException(sprintf('Unknown table "%s".', $table), 1710000130);
        }
        if (!$this->isTableAllowed($table)) {
            throw new \RuntimeException(sprintf('Table "%s" is not configured as translatable.', $table), 1710000131);
        }

        $targetUid = $target->isDefaultLanguage()
            ? $uid
            : $this->resolveOrCreateTranslation($table, $uid, $target, $result);

        if ($targetUid === 0) {
            throw new \RuntimeException('The localized record could not be created.', 1710000132);
        }

        $translatableFields = $this->recordCollector->getTranslatableFields($table);
        $values = [];
        foreach ($units as $unit) {
            if (!$unit->isTranslated()) {
                $result->countSkipped();
                continue;
            }
            if (!isset($translatableFields[$unit->getField()])) {
                // Never trust field names coming from an uploaded file.
                $result->countSkipped();
                continue;
            }
            $values[$unit->getField()] = $unit->getTargetText();
            $unit->setTargetUid($targetUid);
        }

        if ($values === []) {
            return;
        }

        $this->executeDataHandler([$table => [$targetUid => $values]], []);
        $result->countImported(count($values));
    }

    protected function resolveOrCreateTranslation(
        string $table,
        int $uid,
        TranslationTarget $target,
        ImportResult $result
    ): int {
        $existing = $this->recordCollector->findExistingTranslation($table, $uid, $target->getLanguageId());
        if ($existing !== null) {
            return (int)$existing['uid'];
        }

        // v10.4 DataHandler::localize() validates the target language against a
        // sys_language record (removed in v11, which reads the site config). Site
        // languages defined only in config.yaml have no such record, so create a
        // matching one on demand - a one-time bootstrap, keyed by languageId.
        $this->ensureSysLanguageRecord($target);

        $dataHandler = $this->executeDataHandler([], [
            $table => [
                $uid => ['localize' => $target->getLanguageId()],
            ],
        ]);

        $newUid = (int)($dataHandler->copyMappingArray_merged[$table][$uid] ?? 0);
        if ($newUid === 0) {
            $created = $this->recordCollector->findExistingTranslation($table, $uid, $target->getLanguageId());
            $newUid = $created === null ? 0 : (int)$created['uid'];
        }
        if ($newUid > 0) {
            $result->countCreatedRecord();
        }

        return $newUid;
    }

    /**
     * Ensures a sys_language record exists with uid == the site language id, so
     * v10.4's DataHandler::localize() accepts it. No-op for languageId 0 and for
     * ids that already have a record.
     */
    protected function ensureSysLanguageRecord(TranslationTarget $target): void
    {
        $languageId = $target->getLanguageId();
        if ($languageId <= 0) {
            return;
        }
        $connection = $this->connectionPool->getConnectionForTable('sys_language');
        $exists = $connection->count('uid', 'sys_language', ['uid' => $languageId]);
        if ($exists > 0) {
            return;
        }
        $connection->insert('sys_language', [
            'uid' => $languageId,
            'pid' => 0,
            'tstamp' => $GLOBALS['EXEC_TIME'] ?? time(),
            'hidden' => 0,
            'title' => $target->getTitle() !== '' ? $target->getTitle() : ('Language ' . $languageId),
            'flag' => $target->getIsoCode() !== '' ? $target->getIsoCode() : 'multiple',
            'language_isocode' => $target->getIsoCode(),
        ]);
    }

    /**
     * @param array<string, array<int|string, array<string, mixed>>> $dataMap
     * @param array<string, array<int|string, array<string, mixed>>> $commandMap
     */
    protected function executeDataHandler(array $dataMap, array $commandMap): DataHandler
    {
        $dataHandler = GeneralUtility::makeInstance(DataHandler::class);
        $dataHandler->start($dataMap, $commandMap);

        if ($commandMap !== []) {
            $dataHandler->process_cmdmap();
        }
        if ($dataMap !== []) {
            $dataHandler->process_datamap();
        }

        if ($dataHandler->errorLog !== []) {
            throw new \RuntimeException(implode(' | ', $dataHandler->errorLog), 1710000133);
        }

        return $dataHandler;
    }

    /**
     * The translated strings are written into the records of $pageUid (and its
     * subtree). Those records must physically live in the target site - which is
     * the case once the page has been copied into that site's page tree. The
     * source language is irrelevant here; only where the write lands matters.
     */
    protected function assertPageBelongsToTargetSite(int $pageUid, TranslationTarget $target): void
    {
        $site = $this->siteLanguageService->getSiteForPage($pageUid);
        if ($site !== null && $site->getIdentifier() === $target->getSiteIdentifier()) {
            return;
        }

        throw new \RuntimeException(
            sprintf(
                'Page %d is not part of site "%s". Copy the page into that site\'s page tree first, then import the translation against the copied page.',
                $pageUid,
                $target->getSiteIdentifier()
            ),
            1710000134
        );
    }

    protected function isTableAllowed(string $table): bool
    {
        return in_array($table, $this->configuration->getTranslatableTables(), true);
    }

    /**
     * The site configuration is authoritative; a key from a file is only used
     * to look the real target up.
     */
    protected function resolveTarget(TranslationTarget $target): TranslationTarget
    {
        if ($target->getSiteIdentifier() === '') {
            throw new \RuntimeException(
                'The translation file does not contain site information. Import it from the job in the ESET Translator module instead.',
                1710000135
            );
        }

        return $this->siteLanguageService->getTarget($target->getKey());
    }

    /**
     * Takes the translated texts from the uploaded file but keeps the trusted
     * source/target/page information of the job.
     */
    protected function mergeWithJob(TranslationDataSet $uploaded, Job $job): TranslationDataSet
    {
        $source = $this->siteLanguageService->getTarget($job->getSourceKey());
        $target = $this->siteLanguageService->getTarget($job->getTargetKey());

        $merged = new TranslationDataSet($source, $target, $job->getPageUid());
        $merged->setJobIdentifier($job->getJobIdentifier());
        $merged->setTitle($job->getTitle());

        foreach ($job->getItems() as $item) {
            $unit = $item->toUnit();
            $uploadedUnit = $uploaded->getUnit($unit->getId());
            if ($uploadedUnit !== null && $uploadedUnit->isTranslated()) {
                $unit->setTargetText($uploadedUnit->getTargetText());
            }
            $merged->addUnit($unit);
        }

        return $merged;
    }
}
