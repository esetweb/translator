<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationTarget;
use ESET\Translator\Domain\Dto\TranslationUnit;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Collects translatable field values from the page tree.
 *
 * Records are selected by the *site language* of the source target, which means
 * a site whose default language is Czech (languageId 0) can be used as source
 * exactly like a site whose Czech language has uid 7.
 */
class RecordCollectorService
{
    /** @var ConnectionPool */
    protected $connectionPool;

    /** @var ConfigurationService */
    protected $configuration;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    public function __construct(
        ConnectionPool $connectionPool,
        ConfigurationService $configuration,
        SiteLanguageService $siteLanguageService
    ) {
        $this->connectionPool = $connectionPool;
        $this->configuration = $configuration;
        $this->siteLanguageService = $siteLanguageService;
    }

    public function collect(
        int $pageUid,
        TranslationTarget $source,
        TranslationTarget $target,
        int $depth = 0,
        bool $onlyUntranslated = true
    ): TranslationDataSet {
        $dataSet = new TranslationDataSet($source, $target, $pageUid);
        $pageUids = $this->resolvePageUids($pageUid, $source, $depth);

        foreach ($this->configuration->getTranslatableTables() as $table) {
            if (!isset($GLOBALS['TCA'][$table])) {
                continue;
            }
            foreach ($this->fetchRecords($table, $pageUids, $source) as $record) {
                $this->addRecordToDataSet($dataSet, $table, $record, $target, $onlyUntranslated);
            }
        }

        $pageTitle = (string)(BackendUtility::getRecord('pages', $pageUid, 'title')['title'] ?? '');
        $dataSet->setTitle(sprintf('%s → %s (%s)', $pageTitle, $target->getTitle(), $target->getSiteTitle()));

        return $dataSet;
    }

    /**
     * Page uids in the requested depth, restricted to the source language.
     *
     * @return int[]
     */
    public function resolvePageUids(int $pageUid, TranslationTarget $source, int $depth): array
    {
        $maxDepth = min($depth, $this->configuration->getMaxDepth());
        $uids = [$pageUid];
        $current = [$pageUid];

        for ($level = 0; $level < $maxDepth; $level++) {
            $children = $this->fetchChildPageUids($current, $source);
            if ($children === []) {
                break;
            }
            $uids = array_merge($uids, $children);
            $current = $children;
        }

        return array_values(array_unique($uids));
    }

    /**
     * @param int[] $parentUids
     * @return int[]
     */
    protected function fetchChildPageUids(array $parentUids, TranslationTarget $source): array
    {
        if ($parentUids === []) {
            return [];
        }
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('uid')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($parentUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($source->getLanguageId(), \PDO::PARAM_INT)
                )
            )
            ->orderBy('sorting')
            ->execute()
            ->fetchAll();

        return array_map('intval', array_column($rows, 'uid'));
    }

    /**
     * @param int[] $pageUids
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRecords(string $table, array $pageUids, TranslationTarget $source): array
    {
        if ($pageUids === []) {
            return [];
        }
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $constraints = [];
        if ($table === 'pages') {
            $constraints[] = $queryBuilder->expr()->in(
                'uid',
                $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
            );
        } else {
            $constraints[] = $queryBuilder->expr()->in(
                'pid',
                $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
            );
        }

        if ($languageField !== '') {
            $constraints[] = $queryBuilder->expr()->eq(
                $languageField,
                $queryBuilder->createNamedParameter($source->getLanguageId(), \PDO::PARAM_INT)
            );
        }

        return $queryBuilder
            ->select('*')
            ->from($table)
            ->where(...$constraints)
            ->execute()
            ->fetchAll();
    }

    /**
     * @param array<string, mixed> $record
     */
    protected function addRecordToDataSet(
        TranslationDataSet $dataSet,
        string $table,
        array $record,
        TranslationTarget $target,
        bool $onlyUntranslated
    ): void {
        $uid = (int)$record['uid'];
        $pageUid = $table === 'pages' ? $uid : (int)$record['pid'];
        $existingTranslation = $this->findExistingTranslation($table, $uid, $target->getLanguageId());

        foreach ($this->getTranslatableFields($table) as $field => $fieldConfig) {
            if (!isset($record[$field])) {
                continue;
            }
            $value = (string)$record[$field];
            if (trim($value) === '') {
                continue;
            }
            if ($onlyUntranslated
                && $existingTranslation !== null
                && trim((string)($existingTranslation[$field] ?? '')) !== ''
                && (string)($existingTranslation[$field] ?? '') !== $value
            ) {
                // already translated and edited by a human, do not overwrite
                continue;
            }

            $unit = new TranslationUnit(
                $table,
                $uid,
                $field,
                $value,
                $this->isHtmlField($fieldConfig),
                $this->getFieldLabel($table, $field),
                $pageUid
            );
            if ($existingTranslation !== null) {
                $unit->setTargetUid((int)$existingTranslation['uid']);
            }
            if ($table === 'tt_content' && isset($record['CType'])) {
                $unit->setMetaData('CType', (string)$record['CType']);
            }
            $dataSet->addUnit($unit);
        }
    }

    /**
     * @return array<string, array<string, mixed>> field name => TCA config
     */
    public function getTranslatableFields(string $table): array
    {
        $columns = (array)($GLOBALS['TCA'][$table]['columns'] ?? []);
        $fields = [];

        foreach ($columns as $field => $definition) {
            $config = (array)($definition['config'] ?? []);
            if (!$this->isTranslatableField($table, $field, $definition, $config)) {
                continue;
            }
            $fields[$field] = $config;
        }

        return $fields;
    }

    /**
     * @param array<string, mixed> $definition
     * @param array<string, mixed> $config
     */
    protected function isTranslatableField(string $table, string $field, array $definition, array $config): bool
    {
        if ($this->configuration->isFieldExcluded($table, $field)) {
            return false;
        }
        $type = (string)($config['type'] ?? '');
        if (!in_array($type, ['input', 'text'], true)) {
            return false;
        }
        if (!empty($config['readOnly'])) {
            return false;
        }
        // Fields explicitly excluded from localization, or kept in sync with
        // the default language, must never appear in a translation file.
        $l10nMode = (string)($definition['l10n_mode'] ?? '');
        if ($l10nMode === 'exclude') {
            return false;
        }
        if (!empty($config['behaviour']['allowLanguageSynchronization'])) {
            return false;
        }
        $eval = GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true);
        $nonTextEvals = ['int', 'double2', 'date', 'datetime', 'time', 'timesec', 'num', 'password', 'md5'];
        if (array_intersect($eval, $nonTextEvals) !== []) {
            return false;
        }
        if (isset($config['renderType']) && $config['renderType'] === 'inputLink') {
            return false;
        }
        if (isset($config['renderType']) && $config['renderType'] === 'colorpicker') {
            return false;
        }

        return true;
    }

    /**
     * @param array<string, mixed> $fieldConfig
     */
    protected function isHtmlField(array $fieldConfig): bool
    {
        return !empty($fieldConfig['enableRichtext']);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findExistingTranslation(string $table, int $uid, int $languageId): ?array
    {
        $languageField = (string)($GLOBALS['TCA'][$table]['ctrl']['languageField'] ?? '');
        $parentField = (string)($GLOBALS['TCA'][$table]['ctrl']['transOrigPointerField'] ?? '');
        if ($languageField === '' || $parentField === '' || $languageId === 0) {
            return null;
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $row = $queryBuilder
            ->select('*')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq($parentField, $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq($languageField, $queryBuilder->createNamedParameter($languageId, \PDO::PARAM_INT))
            )
            ->setMaxResults(1)
            ->execute()
            ->fetch();

        return $row === false ? null : $row;
    }

    protected function getFieldLabel(string $table, string $field): string
    {
        $label = (string)($GLOBALS['TCA'][$table]['columns'][$field]['label'] ?? $field);
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService instanceof LanguageService && strpos($label, 'LLL:') === 0) {
            $translated = $languageService->sL($label);
            if ($translated !== '') {
                return $translated;
            }
        }

        return $label;
    }
}
