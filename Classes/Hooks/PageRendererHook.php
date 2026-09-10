<?php

declare(strict_types=1);

namespace ESET\Translator\Hooks;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Page\PageRenderer;

/**
 * Makes the JavaScript labels available as TYPO3.lang in the backend.
 *
 * Needed because the page tree context menu is rendered outside of any module
 * of this extension.
 */
class PageRendererHook
{
    /**
     * @param array<string, mixed> $params
     */
    public function addInlineLanguageLabels(array &$params, PageRenderer $pageRenderer): void
    {
        if (!$this->isBackend()) {
            return;
        }
        $pageRenderer->addInlineLanguageLabelFile(
            'EXT:eset_translator/Resources/Private/Language/locallang_js.xlf'
        );
    }

    protected function isBackend(): bool
    {
        if (Environment::isCli()) {
            return false;
        }
        $request = $GLOBALS['TYPO3_REQUEST'] ?? null;
        if ($request === null) {
            return (bool)($GLOBALS['BE_USER'] ?? null);
        }
        $applicationType = $request->getAttribute('applicationType');

        return $applicationType !== null && ((int)$applicationType & 2) === 2;
    }
}
