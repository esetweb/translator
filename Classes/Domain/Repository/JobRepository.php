<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Repository;

use ESET\Translator\Domain\Model\Job;
use TYPO3\CMS\Extbase\Persistence\QueryInterface;
use TYPO3\CMS\Extbase\Persistence\QueryResultInterface;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<Job>
 */
class JobRepository extends Repository
{
    /** @var array<string, string> */
    protected $defaultOrderings = [
        'crdate' => QueryInterface::ORDER_DESCENDING,
    ];

    public function initializeObject(): void
    {
        $querySettings = $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     * @return QueryResultInterface<Job>
     */
    public function findByFilter(array $filter): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = [];

        if (!empty($filter['status'])) {
            $constraints[] = $query->equals('status', $filter['status']);
        }
        if (!empty($filter['site'])) {
            $constraints[] = $query->logicalOr([
                $query->equals('sourceSite', $filter['site']),
                $query->equals('targetSite', $filter['site']),
            ]);
        }
        if (!empty($filter['pageUid'])) {
            $constraints[] = $query->equals('pageUid', (int)$filter['pageUid']);
        }
        if (!empty($filter['backendUserId'])) {
            $constraints[] = $query->equals('backendUserId', (int)$filter['backendUserId']);
        }
        if ($constraints !== []) {
            $query->matching($query->logicalAnd($constraints));
        }

        return $query->execute();
    }

    /**
     * Jobs waiting to be picked up by the CLI command / scheduler task.
     *
     * @return Job[]
     */
    public function findProcessable(int $limit = 5): array
    {
        $query = $this->createQuery();
        $query->matching(
            $query->logicalAnd([
                $query->equals('mode', Job::MODE_AUTOMATED),
                $query->logicalOr([
                    $query->equals('status', Job::STATUS_NEW),
                    $query->equals('status', Job::STATUS_QUEUED),
                ]),
            ])
        );
        $query->setOrderings(['crdate' => QueryInterface::ORDER_ASCENDING]);
        $query->setLimit($limit);

        return $query->execute()->toArray();
    }

    public function findOneByJobIdentifier(string $jobIdentifier): ?Job
    {
        $query = $this->createQuery();
        $query->matching($query->equals('jobIdentifier', $jobIdentifier));
        $query->setLimit(1);

        $result = $query->execute()->getFirst();

        return $result instanceof Job ? $result : null;
    }

    /**
     * @return array<string, int> status => count
     */
    public function countByStatus(): array
    {
        $counts = [];
        foreach ($this->findAll() as $job) {
            $status = $job->getStatus();
            $counts[$status] = ($counts[$status] ?? 0) + 1;
        }

        return $counts;
    }
}
