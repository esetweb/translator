<?php
declare(strict_types=1);

namespace ESET\Translator\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

class JobCleanupService
{
    private const JOB_TABLE = 'tx_esettranslator_domain_model_job';
    private const ITEM_TABLE = 'tx_esettranslator_domain_model_jobitem';

    /**
     * Soft-delete finished jobs older than $retentionDays.
     *
     * @param string[] $statuses
     */
    public function retireFinished(int $retentionDays = 7, array $statuses = ['imported', 'cancelled']): int
    {
        $threshold = time() - ($retentionDays * 86400);
        $queryBuilder = $this->queryBuilder(self::JOB_TABLE);

        $jobUids = $queryBuilder
            ->select('uid')
            ->from(self::JOB_TABLE)
            ->where(
                $queryBuilder->expr()->in(
                    'status',
                    $queryBuilder->createNamedParameter($statuses, Connection::PARAM_STR_ARRAY)
                ),
                $queryBuilder->expr()->gt('finished_at', 0),
                $queryBuilder->expr()->lt('finished_at', $queryBuilder->createNamedParameter($threshold, Connection::PARAM_INT))
            )
            ->execute()
            ->fetchAll();

        $uids = array_map('intval', array_column($jobUids, 'uid'));
        if ($uids === []) {
            return 0;
        }

        $now = time();
        $update = $this->queryBuilder(self::JOB_TABLE);
        $update->update(self::JOB_TABLE)
            ->set('deleted', 1)
            ->set('tstamp', $now)
            ->where($update->expr()->in('uid', $uids))
            ->execute();

        $itemUpdate = $this->queryBuilder(self::ITEM_TABLE);
        $itemUpdate->update(self::ITEM_TABLE)
            ->set('deleted', 1)
            ->set('tstamp', $now)
            ->where($itemUpdate->expr()->in('job', $uids))
            ->execute();

        return count($uids);
    }

    /**
     * Hard-delete rows already marked deleted, older than $purgeDays (by tstamp).
     */
    public function purgeDeleted(int $purgeDays = 30): int
    {
        $threshold = time() - ($purgeDays * 86400);
        $deletedJobs = $this->fetchDeletedUids(self::JOB_TABLE, $threshold);

        $itemQb = $this->queryBuilder(self::ITEM_TABLE);
        $itemQb->delete(self::ITEM_TABLE);
        $constraints = [
            $itemQb->expr()->eq('deleted', 1),
            $itemQb->expr()->lt('tstamp', $itemQb->createNamedParameter($threshold, Connection::PARAM_INT)),
        ];
        if ($deletedJobs !== []) {
            $constraints[] = $itemQb->expr()->orX(
                $itemQb->expr()->in('job', $deletedJobs),
                $itemQb->expr()->andX(
                    $itemQb->expr()->eq('deleted', 1),
                    $itemQb->expr()->lt('tstamp', $itemQb->createNamedParameter($threshold, Connection::PARAM_INT))
                )
            );
            // keep it simple: purge items of retired parents + old deleted items
        }
        // simpler and safer: two deletes
        $removedItems = $this->hardDeleteDeleted(self::ITEM_TABLE, $threshold);
        if ($deletedJobs !== []) {
            $orphanItems = $this->queryBuilder(self::ITEM_TABLE);
            $orphanItems->delete(self::ITEM_TABLE)
                ->where($orphanItems->expr()->in('job', $deletedJobs))
                ->execute();
        }
        $removedJobs = $this->hardDeleteDeleted(self::JOB_TABLE, $threshold);

        return $removedItems + $removedJobs;
    }

    private function hardDeleteDeleted(string $table, int $threshold): int
    {
        $queryBuilder = $this->queryBuilder($table);

        return $queryBuilder->delete($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', 1),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($threshold, Connection::PARAM_INT))
            )
            ->execute();
    }

    /**
     * @return int[]
     */
    private function fetchDeletedUids(string $table, int $threshold): array
    {
        $queryBuilder = $this->queryBuilder($table);
        $rows = $queryBuilder
            ->select('uid')
            ->from($table)
            ->where(
                $queryBuilder->expr()->eq('deleted', 1),
                $queryBuilder->expr()->lt('tstamp', $queryBuilder->createNamedParameter($threshold, Connection::PARAM_INT))
            )
            ->execute()
            ->fetchAll();

        return array_map('intval', array_column($rows, 'uid'));
    }

    private function queryBuilder(string $table): \TYPO3\CMS\Core\Database\Query\QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable($table);
        $queryBuilder->getRestrictions()->removeAll();

        return $queryBuilder;
    }
}