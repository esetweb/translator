<?php

defined('TYPO3_MODE') || die();

(static function (): void {
    $extensionKey = 'eset_translator';
    $languageFile = 'LLL:EXT:' . $extensionKey . '/Resources/Private/Language/locallang_mod.xlf';

    // Top level backend module "ESET".
    \TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addModule(
        'eset',
        '',
        '',
        '',
        [
            'name' => 'eset',
            'access' => 'user,group',
            'iconIdentifier' => 'eset-translator-module',
            'labels' => $languageFile,
        ]
    );

    // Sub module "Translation jobs".
    \TYPO3\CMS\Extbase\Utility\ExtensionUtility::registerModule(
        'EsetTranslator',
        'eset',
        'jobs',
        '',
        [
            \ESET\Translator\Controller\JobController::class => 'index,show,download,upload,run,requeue,cancel,delete',
        ],
        [
            'access' => 'user,group',
            'iconIdentifier' => 'eset-translator-job',
            'labels' => 'LLL:EXT:' . $extensionKey . '/Resources/Private/Language/locallang_mod_jobs.xlf',
        ]
    );
})();
