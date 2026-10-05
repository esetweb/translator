<?php

declare(strict_types=1);

namespace ESET\Translator\Controller;

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Repository\JobRepository;
use ESET\Translator\Service\BackgroundJobDispatcher;
use ESET\Translator\Service\JobService;
use ESET\Translator\Service\PermissionService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Localization\LanguageService;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * AJAX endpoints of the jobs module: start an automated job in the background
 * and tell the module when it is finished.
 */
class JobAjaxController
{
    /** @var JobRepository */
    protected $jobRepository;

    /** @var JobService */
    protected $jobService;

    /** @var PermissionService */
    protected $permissionService;

    /** @var BackgroundJobDispatcher */
    protected $backgroundDispatcher;

    public function __construct(
        JobRepository $jobRepository,
        JobService $jobService,
        PermissionService $permissionService,
        BackgroundJobDispatcher $backgroundDispatcher
    ) {
        $this->jobRepository = $jobRepository;
        $this->jobService = $jobService;
        $this->permissionService = $permissionService;
        $this->backgroundDispatcher = $backgroundDispatcher;
    }

    /**
     * Starts an automated job after this response (PHP-FPM). Without PHP-FPM
     * the job is queued for the scheduler task instead of blocking the request.
     */
    public function runAction(ServerRequestInterface $request): ResponseInterface
    {
        if (!$this->permissionService->canRequestTranslation()) {
            return $this->error('Your backend group is not allowed to request automated translations.');
        }
        $job = $this->findAccessibleJob((int)(((array)$request->getParsedBody())['job'] ?? 0));
        if ($job === null) {
            return $this->error('Unknown translation job.');
        }
        if (!$job->isAutomated()) {
            return $this->error('This job is a manual export/import job and cannot be sent to a provider.');
        }
        if (!$job->isProcessable()) {
            return $this->error(sprintf('The job is "%s" and cannot be started.', $job->getStatus()));
        }

        // Visible right away; the background run switches it to "running".
        $job->setStatus(Job::STATUS_QUEUED);
        $this->jobService->update($job);

        $started = $this->backgroundDispatcher->dispatch($job);

        return new JsonResponse([
            'success' => true,
            'background' => $started,
            'message' => $started
                ? 'The job is running in the background.'
                : 'Background processing needs PHP-FPM - the job is queued for the scheduler task (esettranslator:process).',
            'job' => $this->describe($job),
        ]);
    }

    /**
     * Status of the given jobs: ?jobs=1,2,3
     */
    public function statusAction(ServerRequestInterface $request): ResponseInterface
    {
        $uids = GeneralUtility::intExplode(',', (string)($request->getQueryParams()['jobs'] ?? ''), true);
        $jobs = [];
        foreach (array_slice(array_unique($uids), 0, 100) as $uid) {
            $job = $this->findAccessibleJob($uid);
            if ($job !== null) {
                $jobs[(string)$uid] = $this->describe($job);
            }
        }

        return new JsonResponse(['success' => true, 'jobs' => $jobs]);
    }

    protected function findAccessibleJob(int $uid): ?Job
    {
        if ($uid <= 0) {
            return null;
        }
        $job = $this->jobRepository->findByUid($uid);

        return $job instanceof Job && $this->permissionService->canAccessJob($job) ? $job : null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function describe(Job $job): array
    {
        return [
            'uid' => (int)$job->getUid(),
            'status' => $job->getStatus(),
            'statusLabel' => $this->getStatusLabel($job->getStatus()),
            'active' => in_array($job->getStatus(), [Job::STATUS_QUEUED, Job::STATUS_RUNNING], true),
            'errorMessage' => $job->getErrorMessage(),
        ];
    }

    protected function getStatusLabel(string $status): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if (!$languageService instanceof LanguageService) {
            return $status;
        }
        $label = $languageService->sL('LLL:EXT:eset_translator/Resources/Private/Language/locallang_db.xlf:job.status.' . $status);

        return $label !== '' ? $label : $status;
    }

    /**
     * HTTP 200 on purpose - see TranslationWizardController::error().
     */
    protected function error(string $message): ResponseInterface
    {
        return new JsonResponse(['success' => false, 'message' => $message]);
    }
}
