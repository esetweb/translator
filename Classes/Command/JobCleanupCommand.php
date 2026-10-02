<?php
declare(strict_types=1);

namespace ESET\Translator\Command;

use ESET\Translator\Service\JobCleanupService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class JobCleanupCommand extends Command
{
    /** @var JobCleanupService */
    private $jobCleanupService;

    public function __construct(JobCleanupService $jobCleanupService)
    {
        parent::__construct();
        $this->jobCleanupService = $jobCleanupService;
    }

    protected function configure(): void
    {
        $this->setDescription('Retire finished translation jobs and purge soft-deleted records.');
        $this->addOption('retention-days', null, InputOption::VALUE_REQUIRED, 'Soft-delete finished jobs after N days', '7');
        $this->addOption('purge-days', null, InputOption::VALUE_REQUIRED, 'Hard-delete deleted=1 rows after N days', '30');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $retentionDays = max(1, (int)$input->getOption('retention-days'));
        $purgeDays = max(1, (int)$input->getOption('purge-days'));

        $retired = $this->jobCleanupService->retireFinished($retentionDays);
        $purged = $this->jobCleanupService->purgeDeleted($purgeDays);

        $output->writeln(sprintf('Retired finished jobs: %d', $retired));
        $output->writeln(sprintf('Purged deleted rows: %d', $purged));

        return 0;
    }
}