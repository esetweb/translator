<?php

declare(strict_types=1);

$EM_CONF[$_EXTKEY] = [
    'title' => 'ESET Translator',
    'description' => 'Site aware translation management: export/import translation files (XLIFF, CATXML) and request automated translations from pluggable translation providers. Replaces the sys_language_uid centric workflow of l10nmgr with a (site, site language) based target picker so that sites using languageId 0 as their localized default language are supported.',
    'category' => 'module',
    'author' => 'ESET',
    'state' => 'stable',
    'clearCacheOnLoad' => true,
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '10.4.0-10.4.99',
            'php' => '7.4.0-8.0.99',
        ],
        'conflicts' => [],
        'suggests' => [
            'scheduler' => '',
        ],
    ],
    'autoload' => [
        'psr-4' => [
            'ESET\\Translator\\' => 'Classes/',
        ],
    ],
];
