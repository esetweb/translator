<?php

declare(strict_types=1);

namespace ESET\Translator\Controller;

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Repository\JobRepository;
use ESET\Translator\Format\FormatRegistry;
use ESET\Translator\Provider\ProviderRegistry;
use ESET\Translator\Service\ExportService;
use ESET\Translator\Service\ImportService;
use ESET\Translator\Service\JobService;
use ESET\Translator\Service\PermissionService;
use ESET\Translator\Service\SiteLanguageService;
use ESET\Translator\Service\TranslationRunner;
use TYPO3\CMS\Backend\View\BackendTemplateView;
use TYPO3\CMS\Core\Messaging\AbstractMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend module "Translation jobs": progress overview of automated and manual
 * translation jobs.
 */
class JobController extends ActionController
{
    /** @var string */
    protected $defaultViewObjectName = BackendTemplateView::class;

    /** @var JobRepository */
    protected $jobRepository;

    /** @var JobService */
    protected $jobService;

    /** @var ExportService */
    protected $exportService;

    /** @var ImportService */
    protected $importService;

    /** @var TranslationRunner */
    protected $translationRunner;

    /** @var SiteLanguageService */
    protected $siteLanguageService;

    /** @var PermissionService */
    protected $permissionService;

    /** @var ProviderRegistry */
    protected $providerRegistry;

    /** @var FormatRegistry */
    protected $formatRegistry;

    public function __construct(
        JobRepository $jobRepository,
        JobService $jobService,
        ExportService $exportService,
        ImportService $importService,
        TranslationRunner $translationRunner,
        SiteLanguageService $siteLanguageService,
        PermissionService $permissionService,
        ProviderRegistry $providerRegistry,
        FormatRegistry $formatRegistry
    ) {
        $this->jobRepository = $jobRepository;
        $this->jobService = $jobService;
        $this->exportService = $exportService;
        $this->importService = $importService;
        $this->translationRunner = $translationRunner;
        $this->siteLanguageService = $siteLanguageService;
        $this->permissionService = $permissionService;
        $this->providerRegistry = $providerRegistry;
        $this->formatRegistry = $formatRegistry;
    }

    protected function initializeView(\TYPO3\CMS\Extbase\Mvc\View\ViewInterface $view): void
    {
        if (!$view instanceof BackendTemplateView) {
            return;
        }
        $view->getModuleTemplate()->getPageRenderer()->loadRequireJsModule(
            'TYPO3/CMS/EsetTranslator/TranslationWizard'
        );
        $view->assign('providersAvailable', $this->providerRegistry->hasAnyAvailable());
        $view->assign('availableProviders', $this->providerRegistry->getAvailable());
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int} $filter
     */
    public function indexAction(array $filter = []): void
    {
        $onlyOwn = !($GLOBALS['BE_USER']->isAdmin() ?? false);
        if ($onlyOwn) {
            $filter['backendUserId'] = (int)($GLOBALS['BE_USER']->user['uid'] ?? 0);
        }

        $this->view->assignMultiple([
            'jobs' => $this->jobRepository->findByFilter($filter),
            'filter' => $filter,
            'statusCounts' => $this->jobRepository->countByStatus(),
            'sites' => $this->siteLanguageService->groupBySite($this->permissionService->getAllowedTargets()),
            'statuses' => [
                Job::STATUS_NEW,
                Job::STATUS_EXPORTED,
                Job::STATUS_QUEUED,
                Job::STATUS_RUNNING,
                Job::STATUS_TRANSLATED,
                Job::STATUS_IMPORTED,
                Job::STATUS_FAILED,
                Job::STATUS_CANCELLED,
            ],
        ]);
    }

    public function showAction(Job $job): void
    {
        $this->assertAccess($job);
        $this->view->assignMultiple([
            'job' => $job,
            'source' => $this->siteLanguageService->findTarget($job->getSourceKey()),
            'target' => $this->siteLanguageService->findTarget($job->getTargetKey()),
            'formats' => $this->formatRegistry->getOptions(),
        ]);
    }

    /**
     * Streams the translation file of a job.
     */
    public function downloadAction(Job $job, string $format = ''): string
    {
        $this->assertAccess($job);
        if ($format !== '' && $this->formatRegistry->has($format)) {
            $job->setFormat($format);
        }
        $export = $this->exportService->exportJob($job);

        $this->response->setHeader('Content-Type', $export['contentType'], true);
        $this->response->setHeader(
            'Content-Disposition',
            'attachment; filename="' . $export['fileName'] . '"',
            true
        );
        $this->response->setHeader('Content-Length', (string)strlen($export['content']), true);

        return $export['content'];
    }

    public function uploadAction(Job $job): void
    {
        $this->assertAccess($job);
        $uploadedFile = $_FILES['tx_esettranslator_eset_esettranslatorjobs']['tmp_name']['file'] ?? '';
        $fileName = $_FILES['tx_esettranslator_eset_esettranslatorjobs']['name']['file'] ?? '';

        if (!is_string($uploadedFile) || $uploadedFile === '' || !is_uploaded_file($uploadedFile)) {
            $this->addFlashMessage(
                $this->translate('module.import.noFile'),
                '',
                AbstractMessage::ERROR
            );
            $this->redirect('show', null, null, ['job' => $job]);

            return;
        }

        $content = (string)file_get_contents($uploadedFile);
        try {
            $result = $this->importService->importFile($content, (string)$fileName, $job);
            $job->setImportFile((string)$fileName);
            $job->setImportedCount($result->getImported());
            $job->setStatus($result->hasErrors() ? Job::STATUS_FAILED : Job::STATUS_IMPORTED);
            $job->setErrorMessage($result->hasErrors() ? implode(' | ', $result->getErrors()) : '');
            $job->setFinishedAt(new \DateTime());
            $this->jobService->update($job);

            $this->addFlashMessage(
                $result->getSummary(),
                '',
                $result->hasErrors() ? AbstractMessage::WARNING : AbstractMessage::OK
            );
        } catch (\Throwable $exception) {
            $this->addFlashMessage($exception->getMessage(), '', AbstractMessage::ERROR);
        }

        $this->redirect('show', null, null, ['job' => $job]);
    }

    public function runAction(Job $job): void
    {
        $this->assertAccess($job);
        if (!$job->isAutomated()) {
            $this->addFlashMessage($this->translate('module.run.notAutomated'), '', AbstractMessage::WARNING);
            $this->redirect('show', null, null, ['job' => $job]);

            return;
        }

        $job = $this->translationRunner->run($job);
        $this->addFlashMessage(
            $job->getStatus() === Job::STATUS_FAILED ? $job->getErrorMessage() : $this->translate('module.run.done'),
            '',
            $job->getStatus() === Job::STATUS_FAILED ? AbstractMessage::ERROR : AbstractMessage::OK
        );
        $this->redirect('show', null, null, ['job' => $job]);
    }

    public function requeueAction(Job $job): void
    {
        $this->assertAccess($job);
        foreach ($job->getItems() as $item) {
            if ($item->getStatus() === \ESET\Translator\Domain\Model\JobItem::STATUS_FAILED) {
                $item->setStatus(\ESET\Translator\Domain\Model\JobItem::STATUS_PENDING);
                $item->setErrorMessage('');
            }
        }
        $job->setStatus(Job::STATUS_QUEUED);
        $job->setErrorMessage('');
        $this->jobService->update($job);

        $this->addFlashMessage($this->translate('module.requeue.done'));
        $this->redirect('index');
    }

    public function cancelAction(Job $job): void
    {
        $this->assertAccess($job);
        $job->setStatus(Job::STATUS_CANCELLED);
        $job->setFinishedAt(new \DateTime());
        $this->jobService->update($job);

        $this->addFlashMessage($this->translate('module.cancel.done'));
        $this->redirect('index');
    }

    public function deleteAction(Job $job): void
    {
        $this->assertAccess($job);
        $this->jobRepository->remove($job);
        $this->addFlashMessage($this->translate('module.delete.done'));
        $this->redirect('index');
    }

    protected function assertAccess(Job $job): void
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            throw new \RuntimeException('No backend user.', 1710000150);
        }
        if ($backendUser->isAdmin()) {
            return;
        }
        if ($job->getBackendUserId() === (int)($backendUser->user['uid'] ?? 0)) {
            return;
        }
        $target = $this->siteLanguageService->findTarget($job->getTargetKey());
        if ($target !== null && $this->permissionService->isTargetAllowed($target)) {
            return;
        }

        throw new \RuntimeException('Access denied to this translation job.', 1710000151);
    }

    protected function translate(string $key, array $arguments = []): string
    {
        return (string)LocalizationUtility::translate($key, 'EsetTranslator', $arguments);
    }
}
