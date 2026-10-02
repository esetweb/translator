<?php
declare(strict_types=1);

namespace ESET\Translator\Domain\Repository;

use ESET\Translator\Domain\Model\Job;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMap;
use TYPO3\CMS\Extbase\Persistence\Generic\Mapper\DataMapper;
use TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings;
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
        $querySettings = $this->objectManager->get(Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     * @return QueryResultInterface<Job>
     */
    public function findByFilter(array $filter, int $limit = 0, int $offset = 0): QueryResultInterface
    {
        $query = $this->createQuery();
        $constraints = $this->buildConstraints($query, $filter);
        if ($constraints !== []) {
            $query->matching($query->logicalAnd($constraints));
        }
        if ($limit > 0) {
            $query->setLimit($limit);
            $query->setOffset(max(0, $offset));
        }

        return $query->execute();
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     */
    public function countByFilter(array $filter): int
    {
        $query = $this->createQuery();
        $constraints = $this->buildConstraints($query, $filter);
        if ($constraints !== []) {
            $query->matching($query->logicalAnd($constraints));
        }

        return $query->count();
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
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     * @return array<string, int>
     */
    public function countByStatus(array $filter = []): array
    {
        unset($filter['status']);

        $tableName = $this->getDataMap()->getTableName();
        $statusColumn = $this->getColumnName('status');

        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($tableName);

        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $queryBuilder
            ->select($statusColumn)
            ->addSelectLiteral('COUNT(*) AS cnt')
            ->from($tableName);

        $this->applyFilterToQueryBuilder($queryBuilder, $filter);

        $rows = $queryBuilder
            ->groupBy($statusColumn)
            ->execute()
            ->fetchAll();

        $counts = [];
        foreach ($rows as $row) {
            $counts[(string)$row[$statusColumn]] = (int)$row['cnt'];
        }

        return $counts;
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     * @return array<int, mixed>
     */
    private function buildConstraints(QueryInterface $query, array $filter): array
    {
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

        return $constraints;
    }

    /**
     * @param array{status?: string, site?: string, pageUid?: int, backendUserId?: int} $filter
     */
    private function applyFilterToQueryBuilder(QueryBuilder $queryBuilder, array $filter): void
    {
        if (!empty($filter['site'])) {
            $site = (string)$filter['site'];
            $queryBuilder->andWhere(
                $queryBuilder->expr()->orX(
                    $queryBuilder->expr()->eq(
                        $this->getColumnName('sourceSite'),
                        $queryBuilder->createNamedParameter($site)
                    ),
                    $queryBuilder->expr()->eq(
                        $this->getColumnName('targetSite'),
                        $queryBuilder->createNamedParameter($site)
                    )
                )
            );
        }

        if (!empty($filter['pageUid'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $this->getColumnName('pageUid'),
                    $queryBuilder->createNamedParameter((int)$filter['pageUid'], Connection::PARAM_INT)
                )
            );
        }

        if (!empty($filter['backendUserId'])) {
            $queryBuilder->andWhere(
                $queryBuilder->expr()->eq(
                    $this->getColumnName('backendUserId'),
                    $queryBuilder->createNamedParameter((int)$filter['backendUserId'], Connection::PARAM_INT)
                )
            );
        }
    }

    private function getDataMap(): DataMap
    {
        /** @var DataMapper $dataMapper */
        $dataMapper = $this->objectManager->get(DataMapper::class);

        return $dataMapper->getDataMap(Job::class);
    }

    private function getColumnName(string $propertyName): string
    {
        $columnMap = $this->getDataMap()->getColumnMap($propertyName);
        if ($columnMap === null) {
            throw new \RuntimeException(
                'Missing TCA/column map for Job::' . $propertyName,
                1710000200
            );
        }

        return $columnMap->getColumnName();
    }
}