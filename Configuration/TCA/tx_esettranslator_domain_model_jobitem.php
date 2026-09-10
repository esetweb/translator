<?php

declare(strict_types=1);

$languageFile = 'LLL:EXT:eset_translator/Resources/Private/Language/locallang_db.xlf:';

return [
    'ctrl' => [
        'title' => $languageFile . 'tx_esettranslator_domain_model_jobitem',
        'label' => 'field_name',
        'label_alt' => 'table_name,record_uid',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'delete' => 'deleted',
        'iconfile' => 'EXT:eset_translator/Resources/Public/Icons/job-item.svg',
        'rootLevel' => -1,
        'hideTable' => true,
        'adminOnly' => true,
    ],
    'columns' => [
        'job' => [
            'label' => $languageFile . 'jobitem.job',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'foreign_table' => 'tx_esettranslator_domain_model_job',
                'maxitems' => 1,
            ],
        ],
        'table_name' => [
            'label' => $languageFile . 'jobitem.table_name',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'record_uid' => [
            'label' => $languageFile . 'jobitem.record_uid',
            'config' => ['type' => 'input', 'size' => 8, 'eval' => 'int', 'readOnly' => true],
        ],
        'field_name' => [
            'label' => $languageFile . 'jobitem.field_name',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'record_page_uid' => [
            'label' => $languageFile . 'jobitem.record_page_uid',
            'config' => ['type' => 'input', 'size' => 8, 'eval' => 'int', 'readOnly' => true],
        ],
        'target_uid' => [
            'label' => $languageFile . 'jobitem.target_uid',
            'config' => ['type' => 'input', 'size' => 8, 'eval' => 'int', 'readOnly' => true],
        ],
        'source_text' => [
            'label' => $languageFile . 'jobitem.source_text',
            'config' => ['type' => 'text', 'rows' => 5, 'readOnly' => true],
        ],
        'target_text' => [
            'label' => $languageFile . 'jobitem.target_text',
            'config' => ['type' => 'text', 'rows' => 5],
        ],
        'source_hash' => [
            'label' => $languageFile . 'jobitem.source_hash',
            'config' => ['type' => 'input', 'size' => 40, 'readOnly' => true],
        ],
        'html' => [
            'label' => $languageFile . 'jobitem.html',
            'config' => ['type' => 'check', 'default' => 0],
        ],
        'status' => [
            'label' => $languageFile . 'jobitem.status',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectSingle',
                'items' => [
                    [$languageFile . 'jobitem.status.pending', 'pending'],
                    [$languageFile . 'jobitem.status.translated', 'translated'],
                    [$languageFile . 'jobitem.status.imported', 'imported'],
                    [$languageFile . 'jobitem.status.skipped', 'skipped'],
                    [$languageFile . 'jobitem.status.failed', 'failed'],
                ],
            ],
        ],
        'error_message' => [
            'label' => $languageFile . 'jobitem.error_message',
            'config' => ['type' => 'text', 'rows' => 3, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'table_name, record_uid, field_name, record_page_uid, target_uid, html, status, source_text, target_text, source_hash, error_message',
        ],
    ],
];
