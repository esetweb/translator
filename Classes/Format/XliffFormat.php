<?php

declare(strict_types=1);

namespace ESET\Translator\Format;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationUnit;

/**
 * XLIFF 1.2 – the format every professional translation tool understands
 * (memoQ, Trados, Phrase, XTM, Smartcat, OmegaT, ...).
 *
 * TYPO3 specific context is kept in a custom namespace so it survives a round
 * trip through third party tools without breaking their schema validation.
 */
class XliffFormat extends AbstractFormat
{
    public const IDENTIFIER = 'xliff';

    protected const XLIFF_NS = 'urn:oasis:names:tc:xliff:document:1.2';
    protected const ESET_NS = 'https://www.eset.com/ns/typo3/translator/1.0';

    public function getIdentifier(): string
    {
        return self::IDENTIFIER;
    }

    public function getTitle(): string
    {
        return 'XLIFF 1.2 (standard for translation tools)';
    }

    public function getFileExtension(): string
    {
        return 'xlf';
    }

    public function getContentType(): string
    {
        return 'application/x-xliff+xml';
    }

    public function canImport(string $content, string $fileName): bool
    {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        return in_array($extension, ['xlf', 'xliff'], true)
            || strpos($content, self::XLIFF_NS) !== false;
    }

    public function export(TranslationDataSet $dataSet): string
    {
        $document = $this->createDocument();

        $xliff = $document->createElementNS(self::XLIFF_NS, 'xliff');
        $xliff->setAttribute('version', '1.2');
        $xliff->setAttributeNS('http://www.w3.org/2000/xmlns/', 'xmlns:eset', self::ESET_NS);
        $document->appendChild($xliff);

        $file = $document->createElementNS(self::XLIFF_NS, 'file');
        $file->setAttribute('original', 'typo3/page/' . $dataSet->getPageUid());
        $file->setAttribute('source-language', $dataSet->getSource()->getTranslationCode());
        $file->setAttribute('target-language', $dataSet->getTarget()->getTranslationCode());
        $file->setAttribute('datatype', 'plaintext');
        $file->setAttribute('date', gmdate('Y-m-d\TH:i:s\Z'));
        $file->setAttributeNS(self::ESET_NS, 'eset:source-target', $dataSet->getSource()->getKey());
        $file->setAttributeNS(self::ESET_NS, 'eset:target-target', $dataSet->getTarget()->getKey());
        $file->setAttributeNS(self::ESET_NS, 'eset:page', (string)$dataSet->getPageUid());
        $file->setAttributeNS(self::ESET_NS, 'eset:job', $dataSet->getJobIdentifier());
        $xliff->appendChild($file);

        $header = $document->createElementNS(self::XLIFF_NS, 'header');
        $tool = $document->createElementNS(self::XLIFF_NS, 'tool');
        $tool->setAttribute('tool-id', 'eset_translator');
        $tool->setAttribute('tool-name', 'ESET Translator');
        $header->appendChild($tool);
        $note = $document->createElementNS(self::XLIFF_NS, 'note', $this->escape($dataSet->getTitle()));
        $header->appendChild($note);
        $file->appendChild($header);

        $body = $document->createElementNS(self::XLIFF_NS, 'body');
        $file->appendChild($body);

        foreach ($dataSet->getUnitsGroupedByRecord() as $recordKey => $units) {
            $group = $document->createElementNS(self::XLIFF_NS, 'group');
            $group->setAttribute('id', $recordKey);
            $group->setAttribute('restype', 'row');
            $body->appendChild($group);

            foreach ($units as $unit) {
                $group->appendChild($this->createTransUnit($document, $unit));
            }
        }

        return (string)$document->saveXML();
    }

    protected function createTransUnit(\DOMDocument $document, TranslationUnit $unit): \DOMElement
    {
        $transUnit = $document->createElementNS(self::XLIFF_NS, 'trans-unit');
        $transUnit->setAttribute('id', $unit->getId());
        $transUnit->setAttribute('datatype', $unit->isHtml() ? 'html' : 'plaintext');
        $transUnit->setAttribute('xml:space', 'preserve');
        $transUnit->setAttributeNS(self::ESET_NS, 'eset:table', $unit->getTable());
        $transUnit->setAttributeNS(self::ESET_NS, 'eset:uid', (string)$unit->getUid());
        $transUnit->setAttributeNS(self::ESET_NS, 'eset:field', $unit->getField());
        $transUnit->setAttributeNS(self::ESET_NS, 'eset:page', (string)$unit->getPageUid());
        $transUnit->setAttributeNS(self::ESET_NS, 'eset:hash', $unit->getSourceHash());

        $source = $document->createElementNS(self::XLIFF_NS, 'source');
        $source->appendChild($document->createCDATASection($unit->getSourceText()));
        $transUnit->appendChild($source);

        $target = $document->createElementNS(self::XLIFF_NS, 'target');
        $target->setAttribute('state', $unit->isTranslated() ? 'translated' : 'needs-translation');
        $target->appendChild($document->createCDATASection($unit->getTargetText()));
        $transUnit->appendChild($target);

        if ($unit->getLabel() !== '') {
            $note = $document->createElementNS(self::XLIFF_NS, 'note', $this->escape($unit->getLabel()));
            $note->setAttribute('from', 'typo3');
            $transUnit->appendChild($note);
        }

        return $transUnit;
    }

    public function import(string $content): TranslationDataSet
    {
        $document = $this->loadXml($content);
        $xpath = new \DOMXPath($document);
        $xpath->registerNamespace('x', self::XLIFF_NS);
        $xpath->registerNamespace('eset', self::ESET_NS);

        $fileNode = $xpath->query('//x:file')->item(0);
        if (!$fileNode instanceof \DOMElement) {
            // Some tools strip the default namespace on export.
            $fileNode = $xpath->query('//file')->item(0);
        }
        if (!$fileNode instanceof \DOMElement) {
            throw new \RuntimeException('No <file> element found in the XLIFF document.', 1710000040);
        }

        $dataSet = $this->buildDataSet(
            $fileNode->getAttributeNS(self::ESET_NS, 'source-target'),
            $fileNode->getAttributeNS(self::ESET_NS, 'target-target'),
            (int)$fileNode->getAttributeNS(self::ESET_NS, 'page')
        );
        $dataSet->setJobIdentifier($fileNode->getAttributeNS(self::ESET_NS, 'job'));

        $transUnits = $xpath->query('.//x:trans-unit', $fileNode);
        if ($transUnits === false || $transUnits->length === 0) {
            $transUnits = $xpath->query('.//trans-unit', $fileNode);
        }
        if ($transUnits === false) {
            return $dataSet;
        }

        foreach ($transUnits as $transUnit) {
            if (!$transUnit instanceof \DOMElement) {
                continue;
            }
            $id = $this->readAttribute($transUnit, 'id');
            if ($id === '') {
                continue;
            }
            [$table, $uid, $field] = TranslationUnit::parseId($id);
            $sourceText = $this->readSegment($xpath, $transUnit, 'source');
            $targetText = $this->readSegment($xpath, $transUnit, 'target');

            $unit = new TranslationUnit(
                $table,
                $uid,
                $field,
                $sourceText,
                $this->readAttribute($transUnit, 'datatype') === 'html',
                '',
                (int)$transUnit->getAttributeNS(self::ESET_NS, 'page')
            );
            $unit->setTargetText($targetText);
            $unit->setMetaData('sourceHash', $transUnit->getAttributeNS(self::ESET_NS, 'hash'));
            $dataSet->addUnit($unit);
        }

        return $dataSet;
    }

    protected function readSegment(\DOMXPath $xpath, \DOMElement $transUnit, string $name): string
    {
        $nodes = $xpath->query('./x:' . $name, $transUnit);
        if ($nodes === false || $nodes->length === 0) {
            $nodes = $xpath->query('./' . $name, $transUnit);
        }
        if ($nodes === false || $nodes->length === 0) {
            return '';
        }
        $node = $nodes->item(0);

        return $node === null ? '' : $node->textContent;
    }

    protected function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    }
}
