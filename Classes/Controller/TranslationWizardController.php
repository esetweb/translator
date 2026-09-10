<?php

declare(strict_types=1);

namespace ESET\Translator\Controller;

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Repository\JobRepository;
use ESET\Translator\Format\FormatRegistry;
use ESET\Translator\Provider\ProviderRegistry;
use ESET\Translator\Service\ConfigurationService;
use ESET\Translator\Service\ExportService;
use ESET\Translator\Service\ImportService;
use ESET\Translator\Service\JobService;
use ESET\Translator\Service\PermissionService;
use ESET\Translator\Service\RecordCollectorService;
use ESET\Translator\Service\SiteLanguageService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Message\UploadedFileInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX endpoints behind the page module button and the page tree context menu.
 *
 * Everything the modal needs (allowed targets, providers, formats) is delivered
 * by getOptionsAction, so the JavaScript stays dumb.
 */
class TranslationWizardController
{
    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var PermissionService */
    protected $permissionService;

    /** @var ProviderRegistry */
    protected $providerRegistry;

    /** @var FormatRegistry */
    protected $formatRegistry;

    /** @var JobService */
    protected $jobService;

    /** @var ExportService */
    protected $exportService;

    /** @var ImportService */
    protected $importService;

    /** @var JobRepository */
    protected $jobRepository;

    /** @var ConfigurationService */
    protected $configuration;

    /** @var RecordCollectorService */
    protected $recordCollector;

    public function __construct(
        SiteLanguageService $siteLanguageService,
        PermissionService $permissionService,
        ProviderRegistry $providerRegistry,
        FormatRegistry $formatRegistry,
        JobService $jobService,
        ExportService $exportService,
        ImportService $importService,
        JobRepository $jobRepository,
        ConfigurationService $configuration,
        RecordCollectorService $recordCollector
    ) {
        $this->siteLanguageService = $siteLanguageService;
        $this->permissionService = $permissionService;
        $this->providerRegistry = $providerRegistry;
        $this->formatRegistry = $formatRegistry;
        $this->jobService = $jobService;
        $this->exportService = $exportService;
        $this->importService = $importService;
        $this->jobRepository = $jobRepository;
        $this->configuration = $configuration;
        $this->recordCollector = $recordCollector;
    }

    /**
     * Everything the modal needs for one page.
     */
    public function getOptionsAction(ServerRequestInterface $request): ResponseInterface
    {
        $pageUid = (int)($request->getQueryParams()['page'] ?? 0);
        if ($pageUid <= 0) {
            return $this->error('No page selected.');
        }
        if (!$this->permissionService->canEditPage($pageUid)) {
            return $this->error('You cannot edit this page, so it cannot be translated here. Translate the copy in a site you have edit access to.');
        }

        $pageSite = $this->siteLanguageService->getSiteForPage($pageUid);
        if ($pageSite === null) {
            return $this->error('This page is not part of a configured site. Create a site configuration first.');
        }

        // Target = a language of the site the page physically lives in, filtered
        // by the editor's permissions (be_group web mounts, site roots, TSconfig
        // allow/deny). The translated strings are written into this page:
        //  - languageId 0  => write in place (overwrite the fields) - used when an
        //    English page has been copied into the Slovak/Czech site tree
        //  - languageId N  => create/update the language overlay records
        // Translating into a *different* site means copying the page there first.
        $allowedTargets = array_intersect_key(
            $this->siteLanguageService->getTargetsOfSite($pageSite),
            $this->permissionService->getAllowedTargets()
        );
        if ($allowedTargets === []) {
            return $this->error('You have no permission to write any language of this site.', 403);
        }

        // Source = the language the page is *currently written in*. A freshly
        // copied English page in the SK site holds English text that none of the
        // SK site languages describe, so the editor picks it from any (site,
        // language) they may read.
        $allowedSources = $this->permissionService->getAllowedSources();
        if ($allowedSources === []) {
            return $this->error('You have no permission to read any translation source language.', 403);
        }

        // Nothing to do when the whole installation speaks one language.
        if ($this->onlyDefaultLanguage($allowedTargets) && count($this->siteLanguageService->getAllTargets()) < 2) {
            return $this->error('There is only one language configured in this installation - nothing to translate.');
        }

        // Best guess for the language the page is written in. The editor can
        // still change it in the modal (and is warned while it equals the target).
        $source = $this->resolveDefaultSource($allowedSources, $pageUid);
        if ($source === null) {
            return $this->error('You have no permission to read any translation source language.', 403);
        }

        $providers = [];
        foreach ($allowedTargets as $key => $target) {
            $providers[$key] = array_keys($this->providerRegistry->getAvailableFor($source, $target));
        }

        $page = BackendUtility::getRecord('pages', $pageUid, 'uid,title');

        return new JsonResponse([
            'success' => true,
            'page' => [
                'uid' => $pageUid,
                'title' => (string)($page['title'] ?? ''),
            ],
            'source' => $source,
            'sources' => array_values($allowedSources),
            'targets' => array_values($allowedTargets),
            // tt_content types present in the page (at max configured depth so
            // the list is complete whatever depth the editor picks).
            'contentTypes' => $this->recordCollector->collectContentTypes(
                $pageUid,
                $source,
                $this->configuration->getMaxDepth()
            ),
            'providersPerTarget' => $providers,
            'providers' => array_map(
                static function ($provider): array {
                    return ['id' => $provider->getIdentifier(), 'title' => $provider->getTitle()];
                },
                array_values($this->providerRegistry->getAvailable())
            ),
            'formats' => $this->formatRegistry->getOptions(),
            'defaultFormat' => $this->configuration->getDefaultFormat(),
            'maxDepth' => $this->configuration->getMaxDepth(),
            // Drives the UI fallback: without providers only the manual export
            // is offered instead of an unusable "translate now" button.
            'automatedAvailable' => $this->providerRegistry->hasAnyAvailable(),
            'manualFallback' => $this->configuration->isManualFallbackEnabled(),
        ]);
    }

    /**
     * Creates an automated job (mode=automated) or a manual job (mode=manual).
     */
    public function createJobAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        try {
            $job = $this->createJobFromParams($params);
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        return new JsonResponse([
            'success' => true,
            'job' => [
                'uid' => $job->getUid(),
                'identifier' => $job->getJobIdentifier(),
                'status' => $job->getStatus(),
                'units' => $job->getUnitCount(),
                'mode' => $job->getMode(),
            ],
            'message' => sprintf(
                'Translation job %s created with %d translatable field(s).',
                $job->getJobIdentifier(),
                $job->getUnitCount()
            ),
            'downloadUrl' => $job->isAutomated() ? '' : $this->buildDownloadUrl($job),
        ]);
    }

    /**
     * Creates a manual job and immediately streams the translation file.
     */
    public function exportAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = $request->getMethod() === 'POST'
            ? (array)$request->getParsedBody()
            : $request->getQueryParams();

        try {
            if (!empty($params['job'])) {
                $job = $this->jobRepository->findByUid((int)$params['job']);
                if (!$job instanceof Job) {
                    return $this->error('Unknown job.');
                }
            } else {
                $params['mode'] = Job::MODE_MANUAL;
                $job = $this->createJobFromParams($params);
            }
            $export = $this->exportService->exportJob($job);
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        $response = new Response();
        $response = $response
            ->withHeader('Content-Type', $export['contentType'])
            ->withHeader('Content-Disposition', 'attachment; filename="' . $export['fileName'] . '"')
            ->withHeader('Content-Length', (string)strlen($export['content']));
        $response->getBody()->write($export['content']);

        return $response;
    }

    /**
     * Imports an uploaded translation file.
     */
    public function importAction(ServerRequestInterface $request): ResponseInterface
    {
        $params = (array)$request->getParsedBody();
        $uploadedFiles = $request->getUploadedFiles();
        $file = $uploadedFiles['file'] ?? null;

        if (!$file instanceof UploadedFileInterface || $file->getError() !== UPLOAD_ERR_OK) {
            return $this->error('No translation file was uploaded.');
        }
        if ($file->getSize() !== null && $file->getSize() > 50 * 1024 * 1024) {
            return $this->error('The uploaded file is too large (max. 50 MB).');
        }

        $job = null;
        if (!empty($params['job'])) {
            $found = $this->jobRepository->findByUid((int)$params['job']);
            $job = $found instanceof Job ? $found : null;
        }

        try {
            $result = $this->importService->importFile(
                (string)$file->getStream(),
                (string)$file->getClientFilename(),
                $job
            );
        } catch (\Throwable $exception) {
            return $this->error($exception->getMessage());
        }

        if ($job !== null) {
            $job->setImportFile((string)$file->getClientFilename());
            $job->setImportedCount($result->getImported());
            $job->setStatus($result->hasErrors() ? Job::STATUS_FAILED : Job::STATUS_IMPORTED);
            $job->setErrorMessage($result->hasErrors() ? implode(' | ', $result->getErrors()) : '');
            $job->setFinishedAt(new \DateTime());
            $this->jobService->update($job);
        }

        return new JsonResponse([
            'success' => !$result->hasErrors(),
            'message' => $result->getSummary(),
            'imported' => $result->getImported(),
            'skipped' => $result->getSkipped(),
            'errors' => $result->getErrors(),
        ]);
    }

    /**
     * @param array<string, mixed> $params
     */
    protected function createJobFromParams(array $params): Job
    {
        $pageUid = (int)($params['page'] ?? 0);
        $targetKey = (string)($params['target'] ?? '');
        if ($pageUid <= 0 || $targetKey === '') {
            throw new \RuntimeException('Page and target language are required.', 1710000160);
        }

        $sourceKey = (string)($params['source'] ?? '');
        if ($sourceKey === '') {
            $source = $this->siteLanguageService->getDefaultTargetForPage($pageUid);
            if ($source === null) {
                throw new \RuntimeException('This page is not part of a configured site.', 1710000161);
            }
            $sourceKey = $source->getKey();
        }

        $mode = (string)($params['mode'] ?? Job::MODE_MANUAL);
        if ($mode === Job::MODE_AUTOMATED && !$this->providerRegistry->hasAnyAvailable()) {
            if (!$this->configuration->isManualFallbackEnabled()) {
                throw new \RuntimeException(
                    'Automated translation is not configured on this installation. Please contact your administrator to set up a translation provider.',
                    1710000162
                );
            }
            $mode = Job::MODE_MANUAL;
        }

        $skipCTypes = $params['skipCTypes'] ?? '';
        if (is_string($skipCTypes)) {
            $skipCTypes = GeneralUtility::trimExplode(',', $skipCTypes, true);
        }

        return $this->jobService->createJob($pageUid, $sourceKey, $targetKey, [
            'mode' => $mode,
            'provider' => (string)($params['provider'] ?? ''),
            'format' => (string)($params['format'] ?? ''),
            'depth' => (int)($params['depth'] ?? 0),
            'onlyUntranslated' => (bool)($params['onlyUntranslated'] ?? true),
            'skipCTypes' => (array)$skipCTypes,
        ]);
    }

    /**
     * Origin of the copy (t3_origuid) > extension config > the page's own site
     * language > first readable source.
     *
     * @param \ESET\Translator\Domain\Dto\TranslationTarget[] $allowedSources keyed by target key
     */
    protected function resolveDefaultSource(array $allowedSources, int $pageUid): ?\ESET\Translator\Domain\Dto\TranslationTarget
    {
        $candidateKeys = [];

        $origin = $this->siteLanguageService->getOriginTargetForPage($pageUid);
        if ($origin !== null) {
            $candidateKeys[] = $origin->getKey();
        }

        $configured = $this->configuration->getDefaultSourceLanguage();
        if ($configured !== '') {
            if (isset($allowedSources[$configured])) {
                $candidateKeys[] = $configured;
            } else {
                foreach ($allowedSources as $key => $candidate) {
                    if (strcasecmp($candidate->getTranslationCode(), $configured) === 0
                        || strcasecmp($candidate->getTypo3Language(), $configured) === 0
                        || strcasecmp($candidate->getIsoCode(), $configured) === 0
                    ) {
                        $candidateKeys[] = $key;
                        break;
                    }
                }
            }
        }

        $pageSiteDefault = $this->siteLanguageService->getDefaultTargetForPage($pageUid);
        if ($pageSiteDefault !== null) {
            $candidateKeys[] = $pageSiteDefault->getKey();
        }

        foreach ($candidateKeys as $key) {
            if (isset($allowedSources[$key])) {
                return $allowedSources[$key];
            }
        }

        return reset($allowedSources) ?: null;
    }

    /**
     * @param \ESET\Translator\Domain\Dto\TranslationTarget[] $targets
     */
    protected function onlyDefaultLanguage(array $targets): bool
    {
        foreach ($targets as $target) {
            if ($target->getLanguageId() !== 0) {
                return false;
            }
        }

        return true;
    }

    protected function buildDownloadUrl(Job $job): string
    {
        /** @var \TYPO3\CMS\Backend\Routing\UriBuilder $uriBuilder */
        $uriBuilder = GeneralUtility::makeInstance(\TYPO3\CMS\Backend\Routing\UriBuilder::class);

        return (string)$uriBuilder->buildUriFromRoute('ajax_eset_translator_export', ['job' => $job->getUid()]);
    }

    /**
     * HTTP 200 on purpose: the modal JS reads {success:false, message}. TYPO3's
     * AjaxRequest throws on any non-2xx response before the body is parsed, so a
     * 4xx would surface only as a generic "request failed".
     *
     * @param int $statusCode kept for signature compatibility, ignored
     */
    protected function error(string $message, int $statusCode = 200): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'message' => $message]);
    }
}
