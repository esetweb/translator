<?php

declare(strict_types=1);

namespace ESET\Translator\Format;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationUnit;

/**
 * CATXML – the "TYPO3L10N" flavoured XML produced by EXT:l10nmgr.
 *
 * Provided so translation vendors that already have filters/parsers built for
 * the l10nmgr export can keep using them while TYPO3 side switches to this
 * extension.
 *
 * Structure:
 *
 *   <TYPO3L10N>
 *     <head>...</head>
 *     <pageGrp id="12">
 *       <data table="tt_content" elementUid="34" key="tt_content:34:bodytext"><![CDATA[..]]></data>
 *     </pageGrp>
 *   </TYPO3L10N>
 *
 * Note: l10nmgr encodes its language selection as sys_language_uid in
 * <t3_syslang>. Because that value is ambiguous in a multi site setup, the site
 * aware keys are additionally written to <t3_sourceTarget>/<t3_targetTarget>
 * and those win on import. Older files without them still import, the caller
 * then has to supply source/target explicitly.
 */
class CatXmlFormat extends AbstractFormat
{
    public const IDENTIFIER = 'catxml';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'CATXML (l10nmgr compatible)';
    }

    public function getFileExtension(): string
    {
        return 'xml';
    }

    public function canImport(string $content, string $fileName): bool
    {
        return strpos($content, '<TYPO3L10N') !== false
            || strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'xml';
    }

    public function export(TranslationDataSet $dataSet): string
    {
        $document = $this->createDocument();
        $root = $document->createElement('TYPO3L10N');
        $document->appendChild($root);

        $root->appendChild($this->createHead($document, $dataSet));

        $unitsByPage = [];
        foreach ($dataSet->getUnits() as $unit) {
            $unitsByPage[$unit->getPageUid()][] = $unit;
        }

        foreach ($unitsByPage as $pageUid => $units) {
            $pageGrp = $document->createElement('pageGrp');
            $pageGrp->setAttribute('id', (string)$pageUid);
            $root->appendChild($pageGrp);

            foreach ($units as $unit) {
                $data = $document->createElement('data');
                $data->setAttribute('table', $unit->getTable());
                $data->setAttribute('elementUid', (string)$unit->getUid());
                $data->setAttribute('fieldName', $unit->getField());
                $data->setAttribute('key', $this->buildKey($unit));
                $data->setAttribute('transformations', $unit->isHtml() ? '1' : '0');
                $data->appendChild($document->createCDATASection(
                    $unit->isTranslated() ? $unit->getTargetText() : $unit->getSourceText()
                ));
                $pageGrp->appendChild($data);
            }
        }

        return (string)$document->saveXML();
    }

    protected function createHead(\DOMDocument $document, TranslationDataSet $dataSet): \DOMElement
    {
        $head = $document->createElement('head');
        $values = [
            't3_workspaceId' => '0',
            't3_syslang' => (string)$dataSet->getTarget()->getLanguageId(),
            't3_sourceLang' => $dataSet->getSource()->getTranslationCode(),
            't3_targetLang' => $dataSet->getTarget()->getTranslationCode(),
            't3_sourceTarget' => $dataSet->getSource()->getKey(),
            't3_targetTarget' => $dataSet->getTarget()->getKey(),
            't3_pageId' => (string)$dataSet->getPageUid(),
            't3_count' => (string)$dataSet->count(),
            't3_formatVersion' => '1.0',
            't3_job' => $dataSet->getJobIdentifier(),
            't3_exportDate' => gmdate('Y-m-d\TH:i:s\Z'),
        ];
        foreach ($values as $name => $value) {
            $head->appendChild($document->createElement(
                $name,
                htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8')
            ));
        }

        return $head;
    }

    protected function buildKey(TranslationUnit $unit): string
    {
        return $unit->getTable() . ':' . $unit->getUid() . ':' . $unit->getField();
    }

    public function import(string $content): TranslationDataSet
    {
        $document = $this->loadXml($content);
        $xpath = new \DOMXPath($document);

        $dataSet = $this->buildDataSet(
            $this->readHeadValue($xpath, 't3_sourceTarget'),
            $this->readHeadValue($xpath, 't3_targetTarget'),
            (int)$this->readHeadValue($xpath, 't3_pageId')
        );
        $dataSet->setJobIdentifier($this->readHeadValue($xpath, 't3_job'));

        $nodes = $xpath->query('//pageGrp/data');
        if ($nodes === false) {
            return $dataSet;
        }

        foreach ($nodes as $node) {
            if (!$node instanceof \DOMElement) {
                continue;
            }
            [$table, $uid, $field] = $this->parseDataNode($node);
            if ($table === '' || $uid === 0 || $field === '') {
                continue;
            }
            $pageUid = 0;
            $parent = $node->parentNode;
            if ($parent instanceof \DOMElement) {
                $pageUid = (int)$parent->getAttribute('id');
            }

            $unit = new TranslationUnit(
                $table,
                $uid,
                $field,
                '',
                $this->readAttribute($node, 'transformations') === '1',
                '',
                $pageUid
            );
            $unit->setTargetText($node->textContent);
            $dataSet->addUnit($unit);
        }

        return $dataSet;
    }

    /**
     * @return array{0: string, 1: int, 2: string}
     */
    protected function parseDataNode(\DOMElement $node): array
    {
        $table = $this->readAttribute($node, 'table');
        $uid = (int)$this->readAttribute($node, 'elementUid');
        $field = $this->readAttribute($node, 'fieldName');

        if ($table === '' || $uid === 0 || $field === '') {
            // Fall back to the composite key used by older l10nmgr exports.
            $key = $this->readAttribute($node, 'key');
            $parts = explode(':', $key);
            if (count($parts) >= 3) {
                $table = $table !== '' ? $table : $parts[0];
                $uid = $uid !== 0 ? $uid : (int)$parts[1];
                $field = $field !== '' ? $field : $parts[2];
            }
        }

        return [$table, $uid, $field];
    }

    protected function readHeadValue(\DOMXPath $xpath, string $name): string
    {
        $nodes = $xpath->query('//head/' . $name);
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);

        return $node === null ? '' : trim($node->textContent);
    }
}
