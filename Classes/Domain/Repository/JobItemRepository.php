<?php

declare(strict_types=1);

namespace ESET\Translator\Domain\Repository;

use ESET\Translator\Domain\Model\JobItem;
use TYPO3\CMS\Extbase\Persistence\Repository;

/**
 * @extends Repository<JobItem>
 */
class JobItemRepository extends Repository
{
    public function initializeObject(): void
    {
        $querySettings = $this->objectManager->get(\TYPO3\CMS\Extbase\Persistence\Generic\Typo3QuerySettings::class);
        $querySettings->setRespectStoragePage(false);
        $querySettings->setIgnoreEnableFields(true);
        $this->setDefaultQuerySettings($querySettings);
    }

    /**
     * @return JobItem[]
     */
    public function findByJobUid(int $jobUid): array
    {
        $query = $this->createQuery();
        $query->matching($query->equals('job', $jobUid));

        return $query->execute()->toArray();
    }
}
