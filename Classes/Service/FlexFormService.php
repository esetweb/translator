<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Reads and re-assembles translatable leaf values stored inside FlexForm
 * columns (tt_content.pi_flexform and any other TCA column of type "flex").
 *
 * Custom plugins and grid elements keep their editable labels in FlexForm
 * option sheets rather than in real DB columns; without this those texts are
 * never collected for translation.
 *
 * Scope: flat sheets with TYPO3's default single-language storage
 * (data/<sheet>/lDEF/<field>/vDEF). FlexForm sections/containers and
 * language-split flex (langChildren / vDA) are intentionally left out - they
 * are rare for option sheets and would need their own round-trip handling.
 */
class FlexFormService
{
    /** @var ConfigurationService */
    protected $configuration;

    public function __construct(ConfigurationService $configuration)
    {
        $this->configuration = $configuration;
    }

    /**
     * The TCA columns of a table that are FlexForm columns and not excluded.
     *
     * @return string[]
     */
    public function getFlexFormColumns(string $table): array
    {
        $columns = [];
        foreach ((array)($GLOBALS['TCA'][$table]['columns'] ?? []) as $name => $definition) {
            if ((string)($definition['config']['type'] ?? '') !== 'flex') {
                continue;
            }
            if ($this->configuration->isFieldExcluded($table, (string)$name)) {
                continue;
            }
            $columns[] = (string)$name;
        }

        return $columns;
    }

    /**
     * Translatable leaves of one FlexForm column of one record, with their
     * current stored value.
     *
     * @param array<string, mixed> $row full DB row (must carry the DS pointer
     *        fields, e.g. CType / list_type - a "SELECT *" row does)
     * @return array<int, array{path: string, sheet: string, name: string, value: string, html: bool, label: string}>
     */
    public function extract(string $table, string $field, array $row): array
    {
        $stored = (string)($row[$field] ?? '');
        if (trim($stored) === '') {
            return [];
        }
        $data = GeneralUtility::xml2array($stored);
        if (!is_array($data) || !isset($data['data']) || !is_array($data['data'])) {
            return [];
        }

        $leaves = [];
        foreach ($this->translatableElements($table, $field, $row) as $element) {
            $value = $data['data'][$element['sheet']]['lDEF'][$element['name']]['vDEF'] ?? null;
            if (!is_string($value) || trim($value) === '') {
                continue;
            }
            $leaves[] = [
                'path' => $field . '/' . $element['sheet'] . '/' . $element['name'],
                'sheet' => $element['sheet'],
                'name' => $element['name'],
                'value' => $value,
                'html' => $element['html'],
                'label' => $element['label'],
            ];
        }

        return $leaves;
    }

    /**
     * path => value map for the stored leaves of a column, for quick lookups
     * (e.g. "is this leaf already translated in the overlay record").
     *
     * @param array<string, mixed> $row
     * @return array<string, string>
     */
    public function valueMap(string $table, string $field, array $row): array
    {
        $map = [];
        foreach ($this->extract($table, $field, $row) as $leaf) {
            $map[$leaf['path']] = $leaf['value'];
        }

        return $map;
    }

    public function isFlexPath(string $table, string $fieldPath): bool
    {
        $parts = explode('/', $fieldPath);
        if (count($parts) < 3) {
            return false;
        }

        return (string)($GLOBALS['TCA'][$table]['columns'][$parts[0]]['config']['type'] ?? '') === 'flex';
    }

    /**
     * "pi_flexform/sDEF/settings.header" => ["pi_flexform", "sDEF", "settings.header"]
     *
     * @return array{0: string, 1: string, 2: string}
     */
    public function parseFlexPath(string $fieldPath): array
    {
        $parts = explode('/', $fieldPath);
        $column = (string)array_shift($parts);
        $sheet = (string)array_shift($parts);

        return [$column, $sheet, implode('/', $parts)];
    }

    /**
     * True when the given sheet/field is a real, translatable leaf of the
     * record's resolved data structure. Never trust a field name from a file.
     *
     * @param array<string, mixed> $row
     */
    public function leafExists(string $table, string $field, array $row, string $sheet, string $name): bool
    {
        foreach ($this->translatableElements($table, $field, $row) as $element) {
            if ($element['sheet'] === $sheet && $element['name'] === $name) {
                return true;
            }
        }

        return false;
    }

    /**
     * Nested value structure for DataHandler:
     * [<column> => ['data' => [<sheet> => ['lDEF' => [<name> => ['vDEF' => <value>]]]]]]
     *
     * @param array<int, array{sheet: string, name: string, value: string}> $leaves
     * @return array<string, array<string, mixed>>
     */
    public function buildDataHandlerValue(string $column, array $leaves): array
    {
        $data = [];
        foreach ($leaves as $leaf) {
            $data[$leaf['sheet']]['lDEF'][$leaf['name']]['vDEF'] = $leaf['value'];
        }

        return [$column => ['data' => $data]];
    }

    /**
     * Walks the resolved data structure and returns the flat, translatable
     * input/text elements (value not read here).
     *
     * @param array<string, mixed> $row
     * @return array<int, array{sheet: string, name: string, html: bool, label: string}>
     */
    protected function translatableElements(string $table, string $field, array $row): array
    {
        $structure = $this->resolveDataStructure($table, $field, $row);
        $elements = [];

        // v10.4 FlexFormTools normalises to "sheets"; keep a fallback for a
        // single-sheet structure that still exposes a bare "ROOT".
        $sheets = (array)($structure['sheets'] ?? []);
        if ($sheets === [] && isset($structure['ROOT'])) {
            $sheets = ['sDEF' => ['ROOT' => $structure['ROOT']]];
        }

        foreach ($sheets as $sheetName => $sheet) {
            foreach ((array)($sheet['ROOT']['el'] ?? []) as $elementName => $elementConfig) {
                if (!is_array($elementConfig)) {
                    continue;
                }
                // Sections/containers ("type" => "array" with "section" => 1) are
                // not handled - skip them rather than emit broken paths.
                if (!empty($elementConfig['section']) || (string)($elementConfig['type'] ?? '') === 'array') {
                    continue;
                }
                $config = (array)($elementConfig['config'] ?? []);
                if (!$this->isTranslatableConfig($config)) {
                    continue;
                }
                $elements[] = [
                    'sheet' => (string)$sheetName,
                    'name' => (string)$elementName,
                    'html' => !empty($config['enableRichtext']),
                    'label' => $this->resolveLabel((string)($elementConfig['label'] ?? ''), (string)$elementName),
                ];
            }
        }

        return $elements;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    protected function resolveDataStructure(string $table, string $field, array $row): array
    {
        $fieldTca = $GLOBALS['TCA'][$table]['columns'][$field] ?? null;
        if (!is_array($fieldTca) || (string)($fieldTca['config']['type'] ?? '') !== 'flex') {
            return [];
        }

        try {
            $flexFormTools = GeneralUtility::makeInstance(FlexFormTools::class);
            $identifier = $flexFormTools->getDataStructureIdentifier($fieldTca, $table, $field, $row);
            $structure = $flexFormTools->parseDataStructureByIdentifier($identifier);
        } catch (\Throwable $exception) {
            return [];
        }

        return is_array($structure) ? $structure : [];
    }

    /**
     * Mirrors the config-level checks of
     * RecordCollectorService::isTranslatableField() (the table/field exclusion
     * and l10n_mode checks there operate on the DB-column TCA and do not apply
     * to FlexForm leaves).
     *
     * @param array<string, mixed> $config
     */
    protected function isTranslatableConfig(array $config): bool
    {
        $type = (string)($config['type'] ?? '');
        if (!in_array($type, ['input', 'text'], true)) {
            return false;
        }
        if (!empty($config['readOnly'])) {
            return false;
        }
        $eval = GeneralUtility::trimExplode(',', (string)($config['eval'] ?? ''), true);
        $nonTextEvals = ['int', 'double2', 'date', 'datetime', 'time', 'timesec', 'num', 'password', 'md5'];
        if (array_intersect($eval, $nonTextEvals) !== []) {
            return false;
        }
        $renderType = (string)($config['renderType'] ?? '');
        if (in_array($renderType, ['inputLink', 'colorpicker', 'selectSingle', 'selectMultipleSideBySide'], true)) {
            return false;
        }

        return true;
    }

    protected function resolveLabel(string $label, string $fallback): string
    {
        if ($label === '') {
            return $fallback;
        }
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
