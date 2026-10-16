<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;
use Perspective\SalesDataGenerator\Model\Job as JobModel;

/**
 * Generation job storage.
 */
class Job extends AbstractDb
{
    public const string TABLE = 'perspective_sales_data_generator_job';

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(self::TABLE, JobModel::JOB_ID);
    }

    /**
     * Update only the given columns, without loading and saving the whole job.
     *
     * @param int $jobId
     * @param array<string, mixed> $data
     */
    public function updateColumns(int $jobId, array $data): void
    {
        $this->getConnection()->update($this->getMainTable(), $data, [JobModel::JOB_ID . ' = ?' => $jobId]);
    }

    /**
     * Move a job from one status to another unless another process already did; true when this call did it.
     *
     * @param int $jobId
     * @param list<string> $from
     * @param string $to
     * @return bool
     */
    public function transition(int $jobId, array $from, string $to): bool
    {
        return $this->getConnection()->update(
            $this->getMainTable(),
            [JobModel::STATUS => $to],
            [JobModel::JOB_ID . ' = ?' => $jobId, JobModel::STATUS . ' IN (?)' => $from]
        ) > 0;
    }

    /**
     * IDs of unfinished jobs that made no progress since the given UTC time.
     *
     * @return list<int>
     */
    public function getStalledJobIds(string $before): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable(), [JobModel::JOB_ID])
            ->where(
                JobModel::STATUS . ' IN (?)',
                [JobModel::STATUS_QUEUED, JobModel::STATUS_RUNNING, JobModel::STATUS_FINISHING]
            )
            ->where('COALESCE(' . JobModel::HEARTBEAT_AT . ', ' . JobModel::CREATED_AT . ') < ?', $before);

        return array_map('intval', $connection->fetchCol($select));
    }
}
