<?php

declare(strict_types=1);

namespace ESET\Translator\Service;

use ESET\Translator\Domain\Model\Job;
use ESET\Translator\Domain\Repository\JobRepository;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Log\LogManager;

/**
 * Runs an automated job after the HTTP response has been sent, so the editor
 * does not wait for the provider.
 *
 * TYPO3 emits the response before PHP's shutdown functions run. Under PHP-FPM,
 * fastcgi_finish_request() then closes the connection to the browser while the
 * worker keeps going. Other SAPIs (mod_php, the built-in server) cannot do
 * that - dispatch() returns false and the caller decides what to do instead.
 *
 * The job runs as the current backend user (DataHandler permissions/history).
 * Limits: the FPM pool's request_terminate_timeout still applies, and the
 * worker is busy until the job is done.
 */
class BackgroundJobDispatcher
{
    /** @var TranslationRunner */
    protected $translationRunner;

    /** @var JobRepository */
    protected $jobRepository;

    /** @var LoggerInterface */
    protected $logger;

    /** @var int[] uids of jobs to run once the response is out */
    protected $pending = [];

    public function __construct(
        TranslationRunner $translationRunner,
        JobRepository $jobRepository,
        LogManager $logManager
    ) {
        $this->translationRunner = $translationRunner;
        $this->jobRepository = $jobRepository;
        $this->logger = $logManager->getLogger(__CLASS__);
    }

    public function isAvailable(): bool
    {
        return PHP_SAPI !== 'cli' && function_exists('fastcgi_finish_request');
    }

    /**
     * @return bool false when the job cannot run in the background here
     */
    public function dispatch(Job $job): bool
    {
        if (!$this->isAvailable() || !$job->isAutomated() || (int)$job->getUid() <= 0) {
            return false;
        }
        if ($this->pending === []) {
            register_shutdown_function([$this, 'runPending']);
        }
        $this->pending[] = (int)$job->getUid();

        return true;
    }

    /**
     * Shutdown function - not meant to be called directly.
     *
     * @internal
     */
    public function runPending(): void
    {
        // The response has been emitted by now: hand it to the browser and
        // keep working without a time limit or a client to wait for.
        fastcgi_finish_request();
        ignore_user_abort(true);
        @set_time_limit(0);

        foreach (array_unique($this->pending) as $uid) {
            try {
                $job = $this->jobRepository->findByUid($uid);
                if ($job instanceof Job) {
                    // The runner claims the job atomically, so a scheduler run
                    // picking up the same job at the same time is harmless.
                    $this->translationRunner->run($job);
                }
            } catch (\Throwable $exception) {
                $this->logger->error('Background translation job failed', [
                    'job' => $uid,
                    'exception' => $exception->getMessage(),
                ]);
            }
        }
        $this->pending = [];
    }
}
