<?php

declare(strict_types=1);

namespace ESET\Translator\Command;

use ESET\Translator\Domain\Repository\JobRepository;
use ESET\Translator\Service\TranslationRunner;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Core\Bootstrap;

/**
 * Processes queued automated translation jobs.
 *
 *   vendor/bin/typo3 esettranslator:process --limit=10
 */
class ProcessJobsCommand extends Command
{
    /** @var JobRepository */
    protected $jobRepository;

    /** @var TranslationRunner */
    protected $translationRunner;

    public function __construct(JobRepository $jobRepository, TranslationRunner $translationRunner)
    {
        parent::__construct();
        $this->jobRepository = $jobRepository;
        $this->translationRunner = $translationRunner;
    }

    protected function configure(): void
    {
        $this->setDescription('Processes queued ESET Translator jobs by sending them to the configured translation provider.');
        $this->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Maximum number of jobs to process', '5');
        $this->addOption('no-import', null, InputOption::VALUE_NONE, 'Only translate, do not write the result back into the page tree');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        Bootstrap::initializeBackendAuthentication();

        $io = new SymfonyStyle($input, $output);
        $limit = max(1, (int)$input->getOption('limit'));
        $autoImport = !$input->getOption('no-import');

        $jobs = $this->jobRepository->findProcessable($limit);
        if ($jobs === []) {
            $io->writeln('No queued translation jobs.');

            return 0;
        }

        $failed = 0;
        foreach ($jobs as $job) {
            $io->section(sprintf('Job %s (%s → %s)', $job->getJobIdentifier(), $job->getSourceKey(), $job->getTargetKey()));
            $job = $this->translationRunner->run($job, $autoImport);

            if ($job->getStatus() === \ESET\Translator\Domain\Model\Job::STATUS_FAILED) {
                $io->error($job->getErrorMessage());
                $failed++;
            } else {
                $io->success(sprintf(
                    '%d/%d units translated, %d imported.',
                    $job->getTranslatedCount(),
                    $job->getUnitCount(),
                    $job->getImportedCount()
                ));
            }
        }

        return $failed > 0 ? 1 : 0;
    }
}
