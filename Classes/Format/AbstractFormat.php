<?php

declare(strict_types=1);

namespace ESET\Translator\Format;

use ESET\Translator\Domain\Dto\TranslationDataSet;
use ESET\Translator\Domain\Dto\TranslationTarget;

abstract class AbstractFormat implements FormatInterface
{
    public function getContentType(): string
    {
        return 'application/xml';
    }

    public function canImport(string $content, string $fileName): bool
    {
        return strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === $this->getFileExtension();
    }

    /**
     * Loads XML without resolving external entities (XXE protection) and
     * without network access.
     */
    protected function loadXml(string $content): \DOMDocument
    {
        $content = trim($content);
        if ($content === '') {
            throw new \RuntimeException('The uploaded translation file is empty.', 1710000030);
        }

        $previousErrors = libxml_use_internal_errors(true);
        $previousEntityLoader = null;
        if (LIBXML_VERSION < 20900 && function_exists('libxml_disable_entity_loader')) {
            $previousEntityLoader = libxml_disable_entity_loader(true);
        }

        try {
            $document = new \DOMDocument();
            $document->preserveWhiteSpace = true;
            $loaded = $document->loadXML($content, LIBXML_NONET | LIBXML_COMPACT);
            if ($loaded === false) {
                $errors = array_map(
                    static function (\LibXMLError $error): string {
                        return trim($error->message) . ' (line ' . $error->line . ')';
                    },
                    libxml_get_errors()
                );

                throw new \RuntimeException(
                    'The translation file is not valid XML: ' . implode('; ', $errors),
                    1710000031
                );
            }
            $this->assertNoDoctype($document);

            return $document;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previousErrors);
            if ($previousEntityLoader !== null) {
                libxml_disable_entity_loader($previousEntityLoader);
            }
        }
    }

    private function assertNoDoctype(\DOMDocument $document): void
    {
        if ($document->doctype !== null) {
            throw new \RuntimeException('Translation files must not contain a DOCTYPE declaration.', 1710000032);
        }
    }

    protected function createDocument(): \DOMDocument
    {
        $document = new \DOMDocument('1.0', 'UTF-8');
        $document->formatOutput = true;
        $document->preserveWhiteSpace = false;

        return $document;
    }

    protected function readAttribute(\DOMElement $element, string $name, string $default = ''): string
    {
        return $element->hasAttribute($name) ? $element->getAttribute($name) : $default;
    }

    /**
     * Rebuilds the source/target pair stored in the file header. Falls back to
     * placeholder targets so a file can still be imported when the site
     * configuration was renamed in the meantime (the caller decides).
     */
    protected function buildDataSet(string $sourceKey, string $targetKey, int $pageUid): TranslationDataSet
    {
        $source = $sourceKey !== '' ? TranslationTarget::fromKey($sourceKey) : new TranslationTarget('', 0);
        $target = $targetKey !== '' ? TranslationTarget::fromKey($targetKey) : new TranslationTarget('', 0);

        return new TranslationDataSet($source, $target, $pageUid);
    }
}
