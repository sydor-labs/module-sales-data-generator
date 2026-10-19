<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Category IDs of a job by planned position.
 */
class JobCategory
{
    public const string TABLE = 'perspective_sales_data_generator_category';

    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    public function save(int $jobId, int $position, int $categoryId): void
    {
        $this->getConnection()->insertOnDuplicate(
            $this->getTable(),
            ['job_id' => $jobId, 'position' => $position, 'category_id' => $categoryId],
            ['category_id']
        );
    }

    /**
     * @param int $jobId
     * @param array<int, int> $categoryIds category ID by position
     */
    public function saveMultiple(int $jobId, array $categoryIds): void
    {
        foreach (array_chunk($categoryIds, self::INSERT_CHUNK, true) as $chunk) {
            $rows = [];
            foreach ($chunk as $position => $categoryId) {
                $rows[] = ['job_id' => $jobId, 'position' => $position, 'category_id' => $categoryId];
            }
            $this->getConnection()->insertOnDuplicate($this->getTable(), $rows, ['category_id']);
        }
    }

    /**
     * @param int $jobId
     * @param list<int> $positions
     * @return array<int, int> category ID by position; positions without a category are left out
     */
    public function getIds(int $jobId, array $positions): array
    {
        if ($positions === []) {
            return [];
        }
        $connection = $this->getConnection();

        return $this->toIds($connection->fetchPairs(
            $connection->select()
                ->from($this->getTable(), ['position', 'category_id'])
                ->where('job_id = ?', $jobId)
                ->where('position IN (?)', array_values(array_unique($positions)))
        ));
    }

    /**
     * @return array<int, int> category ID by position within [$from, $to]
     */
    public function getRange(int $jobId, int $from, int $to): array
    {
        $connection = $this->getConnection();

        return $this->toIds($connection->fetchPairs(
            $connection->select()
                ->from($this->getTable(), ['position', 'category_id'])
                ->where('job_id = ?', $jobId)
                ->where('position >= ?', $from)
                ->where('position <= ?', $to)
        ));
    }

    /**
     * @param array<int|string, int|string> $pairs
     * @return array<int, int>
     */
    private function toIds(array $pairs): array
    {
        $ids = [];
        foreach ($pairs as $position => $categoryId) {
            $ids[(int)$position] = (int)$categoryId;
        }

        return $ids;
    }

    private function getTable(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
