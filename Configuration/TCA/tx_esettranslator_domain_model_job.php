<?php

declare(strict_types=1);

$languageFile = 'LLL:EXT:eset_translator/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $languageFile . 'tx_esettranslator_domain_model_job',
        'label' => 'title',
        'label_alt' => 'job_identifier',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'cruser_id' => 'cruser_id',
        'delete' => 'deleted',
        'default_sortby' => 'ORDER BY crdate DESC',
        'iconfile' => 'EXT:eset_translator/Resources/Public/Icons/job.svg',
        'rootLevel' => -1,
        'hideTable' => true,
        'adminOnly' => true,
        'searchFields' => 'job_identifier,title,source_site,target_site',
    ],
    'columns' => [
        'job_identifier' => [
            'label' => $languageFile . 'job.job_identifier',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'title' => [
            'label' => $languageFile . 'job.title',
            'config' => ['type' => 'input', 'size' => 50, 'eval' => 'trim'],
        ],
        'page_uid' => [
            'label' => $languageFile . 'job.page_uid',
            'config' => ['type' => 'group', 'internal_type' => 'db', 'allowed' => 'pages', 'size' => 1, 'maxitems' => 1],
        ],
        'depth' => [
            'label' => $languageFile . 'job.depth',
            'config' => ['type' => 'input', 'size' => 5, 'eval' => 'int'],
        ],
        'source_site' => [
            'label' => $languageFile . 'job.source_site',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'source_language_id' => [
            'label' => $languageFile . 'job.source_language_id',
            'config' => ['type' => 'input', 'size' => 5, 'eval' => 'int', 'readOnly' => true],
        ],
        'target_site' => [
            'label' => $languageFile . 'job.target_site',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'target_language_id' => [
            'label' => $languageFile . 'job.target_language_id',
            'config' => ['type' => 'input', 'size' => 5, 'eval' => 'int', 'readOnly' => true],
        ],
        'mode' => [
            'label' => $languageFile . 'job.mode',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [$languageFile . 'job.mode.manual', 'manual'],
                    [$languageFile . 'job.mode.automated', 'automated'],
                ],
            ],
        ],
        'provider' => [
            'label' => $languageFile . 'job.provider',
            'config' => ['type' => 'input', 'size' => 30],
        ],
        'format' => [
            'label' => $languageFile . 'job.format',
            'config' => ['type' => 'input', 'size' => 30],
        ],
        'status' => [
            'label' => $languageFile . 'job.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [$languageFile . 'job.status.new', 'new'],
                    [$languageFile . 'job.status.exported', 'exported'],
                    [$languageFile . 'job.status.queued', 'queued'],
                    [$languageFile . 'job.status.running', 'running'],
                    [$languageFile . 'job.status.translated', 'translated'],
                    [$languageFile . 'job.status.imported', 'imported'],
                    [$languageFile . 'job.status.failed', 'failed'],
                    [$languageFile . 'job.status.cancelled', 'cancelled'],
                ],
            ],
        ],
        'unit_count' => [
            'label' => $languageFile . 'job.unit_count',
            'config' => ['type' => 'input', 'size' => 6, 'eval' => 'int', 'readOnly' => true],
        ],
        'translated_count' => [
            'label' => $languageFile . 'job.translated_count',
            'config' => ['type' => 'input', 'size' => 6, 'eval' => 'int', 'readOnly' => true],
        ],
        'imported_count' => [
            'label' => $languageFile . 'job.imported_count',
            'config' => ['type' => 'input', 'size' => 6, 'eval' => 'int', 'readOnly' => true],
        ],
        'error_message' => [
            'label' => $languageFile . 'job.error_message',
            'config' => ['type' => 'text', 'rows' => 4, 'readOnly' => true],
        ],
        'export_file' => [
            'label' => $languageFile . 'job.export_file',
            'config' => ['type' => 'input', 'size' => 50, 'readOnly' => true],
        ],
        'import_file' => [
            'label' => $languageFile . 'job.import_file',
            'config' => ['type' => 'input', 'size' => 50, 'readOnly' => true],
        ],
        'backend_user_id' => [
            'label' => $languageFile . 'job.backend_user_id',
            'config' => ['type' => 'group', 'internal_type' => 'db', 'allowed' => 'be_users', 'size' => 1, 'maxitems' => 1],
        ],
        'started_at' => [
            'label' => $languageFile . 'job.started_at',
            'config' => ['type' => 'input', 'renderType' => 'inputDateTime', 'eval' => 'datetime,int', 'readOnly' => true],
        ],
        'finished_at' => [
            'label' => $languageFile . 'job.finished_at',
            'config' => ['type' => 'input', 'renderType' => 'inputDateTime', 'eval' => 'datetime,int', 'readOnly' => true],
        ],
        'items' => [
            'label' => $languageFile . 'job.items',
            'config' => [
                'type' => 'inline',
                'foreign_table' => 'tx_esettranslator_domain_model_jobitem',
                'foreign_field' => 'job',
                'maxitems' => 99999,
                'appearance' => ['collapseAll' => true, 'expandSingle' => true],
            ],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'job_identifier, title, page_uid, depth,
                --div--;' . $languageFile . 'job.tab.languages, source_site, source_language_id, target_site, target_language_id,
                --div--;' . $languageFile . 'job.tab.processing, mode, provider, format, status, unit_count, translated_count, imported_count, started_at, finished_at, error_message,
                --div--;' . $languageFile . 'job.tab.files, export_file, import_file, backend_user_id,
                --div--;' . $languageFile . 'job.tab.items, items',
        ],
    ],
];
