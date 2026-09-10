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

    /**
     * @param string[] $skipCTypes tt_content CType values to leave out of this job
     */
    public function collect(
        int $pageUid,
        TranslationTarget $source,
        TranslationTarget $target,
        int $depth = 0,
        bool $onlyUntranslated = true,
        array $skipCTypes = []
    ): TranslationDataSet {
        $dataSet = new TranslationDataSet($source, $target, $pageUid);
        $pageUids = $this->resolvePageUids($pageUid, $source, $depth);

        foreach ($this->configuration->getTranslatableTables() as $table) {
            if (!isset($GLOBALS['TCA'][$table])) {
                continue;
            }
            foreach ($this->fetchRecords($table, $pageUids, $source, $skipCTypes) as $record) {
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
     * @param string[] $skipCTypes
     * @return array<int, array<string, mixed>>
     */
    protected function fetchRecords(string $table, array $pageUids, TranslationTarget $source, array $skipCTypes = []): array
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

        $skipCTypes = array_values(array_filter($skipCTypes));
        if ($table === 'tt_content' && $skipCTypes !== [] && isset($GLOBALS['TCA']['tt_content']['columns']['CType'])) {
            $constraints[] = $queryBuilder->expr()->notIn(
                'CType',
                $queryBuilder->createNamedParameter($skipCTypes, \TYPO3\CMS\Core\Database\Connection::PARAM_STR_ARRAY)
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
     * The tt_content content types present in the page subtree, for the wizard's
     * "skip content types" checkboxes.
     *
     * @return array<int, array{cType: string, label: string, count: int, excludedByDefault: bool}>
     */
    public function collectContentTypes(int $pageUid, TranslationTarget $source, int $depth): array
    {
        if (!isset($GLOBALS['TCA']['tt_content']['columns']['CType'])) {
            return [];
        }
        $pageUids = $this->resolvePageUids($pageUid, $source, $depth);
        if ($pageUids === []) {
            return [];
        }

        $languageField = (string)($GLOBALS['TCA']['tt_content']['ctrl']['languageField'] ?? 'sys_language_uid');
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tt_content');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->select('CType')
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($pageUids, \TYPO3\CMS\Core\Database\Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    $languageField,
                    $queryBuilder->createNamedParameter($source->getLanguageId(), \PDO::PARAM_INT)
                )
            )
            ->groupBy('CType')
            ->execute()
            ->fetchAll();

        $excluded = $this->configuration->getExcludedCTypes();
        $labels = $this->getCTypeLabels();
        $types = [];
        foreach ($rows as $row) {
            $cType = (string)$row['CType'];
            $types[] = [
                'cType' => $cType,
                'label' => $labels[$cType] ?? $cType,
                'count' => (int)$row['cnt'],
                'excludedByDefault' => in_array($cType, $excluded, true),
            ];
        }
        usort($types, static function (array $a, array $b): int {
            return strcmp($a['label'], $b['label']);
        });

        return $types;
    }

    /**
     * @return array<string, string> CType value => resolved label
     */
    protected function getCTypeLabels(): array
    {
        $items = (array)($GLOBALS['TCA']['tt_content']['columns']['CType']['config']['items'] ?? []);
        $languageService = $GLOBALS['LANG'] ?? null;
        $labels = [];
        foreach ($items as $item) {
            $value = (string)($item[1] ?? '');
            if ($value === '' || $value === '--div--') {
                continue;
            }
            $label = (string)($item[0] ?? $value);
            if ($languageService instanceof LanguageService && strpos($label, 'LLL:') === 0) {
                $label = $languageService->sL($label) ?: $value;
            }
            $labels[$value] = $label;
        }

        return $labels;
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
            if ($onlyUntranslated) {
                // Overlay: skip fields whose overlay record already holds a
                // translation (value differs from the default language).
                if ($existingTranslation !== null
                    && trim((string)($existingTranslation[$field] ?? '')) !== ''
                    && (string)($existingTranslation[$field] ?? '') !== $value
                ) {
                    continue;
                }
                // In place (languageId 0): no overlay record exists - the field
                // itself is the translation. Skip when the value already differs
                // from the record this one was copied from (t3_origuid) - it has
                // been translated, manually or by a previous job - or when this
                // extension imported exactly this value before.
                if ($target->getLanguageId() === 0
                    && ($this->differsFromCopyOrigin($table, $record, $field)
                        || $this->wasImportedInPlace($table, $uid, $field, $value))
                ) {
                    continue;
                }
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
     * True when the field value no longer matches the record this one was copied
     * from (t3_origuid), i.e. it has been translated/edited since the copy.
     * Returns false when the record is not a copy (origin unknown).
     *
     * @param array<string, mixed> $record
     */
    protected function differsFromCopyOrigin(string $table, array $record, string $field): bool
    {
        $origUidField = (string)($GLOBALS['TCA'][$table]['ctrl']['origUid'] ?? '');
        if ($origUidField === '') {
            return false;
        }
        $originUid = (int)($record[$origUidField] ?? 0);
        if ($originUid <= 0 || $originUid === (int)$record['uid']) {
            return false;
        }
        $origin = BackendUtility::getRecord($table, $originUid, $field);
        if ($origin === null || !array_key_exists($field, $origin)) {
            return false;
        }

        return (string)$origin[$field] !== (string)($record[$field] ?? '');
    }

    /**
     * True when a completed ESET import already wrote this exact value into the
     * field - i.e. our previous in-place translation is still there untouched.
     */
    protected function wasImportedInPlace(string $table, int $uid, string $field, string $currentValue): bool
    {
        $queryBuilder = $this->connectionPool->getQueryBuilderForTable('tx_esettranslator_domain_model_jobitem');
        $queryBuilder->getRestrictions()->removeAll()->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $count = $queryBuilder
            ->count('uid')
            ->from('tx_esettranslator_domain_model_jobitem')
            ->where(
                $queryBuilder->expr()->eq('table_name', $queryBuilder->createNamedParameter($table)),
                $queryBuilder->expr()->eq('record_uid', $queryBuilder->createNamedParameter($uid, \PDO::PARAM_INT)),
                $queryBuilder->expr()->eq('field_name', $queryBuilder->createNamedParameter($field)),
                $queryBuilder->expr()->eq('status', $queryBuilder->createNamedParameter(\ESET\Translator\Domain\Model\JobItem::STATUS_IMPORTED)),
                $queryBuilder->expr()->eq('target_text', $queryBuilder->createNamedParameter($currentValue))
            )
            ->execute()
            ->fetchColumn(0);

        return (int)$count > 0;
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
