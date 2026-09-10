<?php

declare(strict_types=1);

use ESET\Translator\Controller\TranslationWizardController;

/**
 * Backend AJAX routes. The route identifiers are prefixed with "ajax_" by the
 * core, e.g. "ajax_eset_translator_export".
 */
return [
    'eset_translator_options' => [
        'path' => '/eset-translator/options',
        'target' => TranslationWizardController::class . '::getOptionsAction',
    ],
    'eset_translator_create_job' => [
        'path' => '/eset-translator/job/create',
        'target' => TranslationWizardController::class . '::createJobAction',
        'methods' => ['POST'],
    ],
    'eset_translator_export' => [
        'path' => '/eset-translator/export',
        'target' => TranslationWizardController::class . '::exportAction',
    ],
    'eset_translator_import' => [
        'path' => '/eset-translator/import',
        'target' => TranslationWizardController::class . '::importAction',
        'methods' => ['POST'],
    ],
];
