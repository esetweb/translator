<?php

declare(strict_types=1);

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Model\JobItem;

/**
 * The vendor namespace (ESET\Translator) does not match the extension key
 * (eset_translator), so the table names have to be mapped explicitly.
 */
return [
    Job::class => [
        'tableName' => 'tx_esettranslator_domain_model_job',
        'properties' => [
            'jobIdentifier' => ['fieldName' => 'job_identifier'],
            'pageUid' => ['fieldName' => 'page_uid'],
            'sourceSite' => ['fieldName' => 'source_site'],
            'sourceLanguageId' => ['fieldName' => 'source_language_id'],
            'targetSite' => ['fieldName' => 'target_site'],
            'targetLanguageId' => ['fieldName' => 'target_language_id'],
            'unitCount' => ['fieldName' => 'unit_count'],
            'translatedCount' => ['fieldName' => 'translated_count'],
            'importedCount' => ['fieldName' => 'imported_count'],
            'errorMessage' => ['fieldName' => 'error_message'],
            'exportFile' => ['fieldName' => 'export_file'],
            'importFile' => ['fieldName' => 'import_file'],
            'backendUserId' => ['fieldName' => 'backend_user_id'],
            'startedAt' => ['fieldName' => 'started_at'],
            'finishedAt' => ['fieldName' => 'finished_at'],
        ],
    ],
    JobItem::class => [
        'tableName' => 'tx_esettranslator_domain_model_jobitem',
        'properties' => [
            'tableName' => ['fieldName' => 'table_name'],
            'recordUid' => ['fieldName' => 'record_uid'],
            'fieldName' => ['fieldName' => 'field_name'],
            'recordPageUid' => ['fieldName' => 'record_page_uid'],
            'targetUid' => ['fieldName' => 'target_uid'],
            'sourceText' => ['fieldName' => 'source_text'],
            'targetText' => ['fieldName' => 'target_text'],
            'sourceHash' => ['fieldName' => 'source_hash'],
            'errorMessage' => ['fieldName' => 'error_message'],
        ],
    ],
];
