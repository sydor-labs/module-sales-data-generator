<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Segments of a job: the categories and products to create and the customers and orders of each day,
 * with how many are processed.
 *
 * @phpstan-type SegmentRow array{segment_id: int, job_id: int, type: string, day: string, planned_count: int,
 *     first_position: int, done_count: int, failed_count: int, summary: string|null, start_entity_id: int|null}
 */
class Segment
{
    public const string TABLE = 'perspective_sales_data_generator_segment';
    public const string TYPE_CATEGORY = 'category';
    public const string TYPE_PRODUCT = 'product';
    public const string TYPE_CUSTOMER = 'customer';
    public const string TYPE_ORDER = 'order';

    private const int INSERT_CHUNK = 1000;

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * Insert the days of one type in chronological order; positions run through all of them.
     *
     * @param int $jobId
     * @param string $type
     * @param array<string, int> $dayCounts count by day (Y-m-d), chronological
     */
    public function insertDays(int $jobId, string $type, array $dayCounts): void
    {
        $rows = [];
        $position = 0;
        foreach ($dayCounts as $day => $count) {
            $rows[] = [
                'job_id' => $jobId,
                'type' => $type,
                'day' => $day,
                'planned_count' => $count,
                'first_position' => $position,
            ];
            $position += $count;
            if (count($rows) >= self::INSERT_CHUNK) {
                $this->getConnection()->insertMultiple($this->getTable(), $rows);
                $rows = [];
            }
        }
        if ($rows !== []) {
            $this->getConnection()->insertMultiple($this->getTable(), $rows);
        }
    }

    /**
     * Unfinished segments in processing order: by day, then by type. Catalog segments are planned for
     * the day before the first customer, so they come first; categories sort before products.
     *
     * @return list<SegmentRow>
     */
    public function getUnfinished(int $jobId, int $limit): array
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getTable())
            ->where('job_id = ?', $jobId)
            ->where('done_count < planned_count')
            ->order(['day ASC', 'type ASC'])
            ->limit($limit);

        return array_map([$this, 'normalize'], $connection->fetchAll($select));
    }

    /**
     * @return SegmentRow|null
     */
    public function get(int $segmentId): ?array
    {
        $connection = $this->getConnection();
        $row = $connection->fetchRow(
            $connection->select()->from($this->getTable())->where('segment_id = ?', $segmentId)
        );

        return $row ? $this->normalize($row) : null;
    }

    /**
     * Whether every customer registered up to the end of the day already exists.
     */
    public function areCustomersDoneUntil(int $jobId, string $day): bool
    {
        $connection = $this->getConnection();

        return !$connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), ['segment_id'])
                ->where('job_id = ?', $jobId)
                ->where('type = ?', self::TYPE_CUSTOMER)
                ->where('day <= ?', $day)
                ->where('done_count < planned_count')
                ->limit(1)
        );
    }

    /**
     * Whether no segment of the given types is left unfinished.
     *
     * @param int $jobId
     * @param list<string> $types
     * @return bool
     */
    public function areDone(int $jobId, array $types): bool
    {
        $connection = $this->getConnection();

        return !$connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), ['segment_id'])
                ->where('job_id = ?', $jobId)
                ->where('type IN (?)', $types)
                ->where('done_count < planned_count')
                ->limit(1)
        );
    }

    public function hasUnfinished(int $jobId): bool
    {
        $connection = $this->getConnection();

        return (bool)$connection->fetchOne(
            $connection->select()
                ->from($this->getTable(), ['segment_id'])
                ->where('job_id = ?', $jobId)
                ->where('done_count < planned_count')
                ->limit(1)
        );
    }

    /**
     * Customer days of a job with their position ranges, chronological.
     *
     * @return list<array{day: string, planned_count: int, first_position: int}>
     */
    public function getCustomerDays(int $jobId): array
    {
        $connection = $this->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from($this->getTable(), ['day', 'planned_count', 'first_position'])
                ->where('job_id = ?', $jobId)
                ->where('type = ?', self::TYPE_CUSTOMER)
                ->order('day ASC')
        );

        return array_map(
            static fn (array $row): array => [
                'day' => (string)$row['day'],
                'planned_count' => (int)$row['planned_count'],
                'first_position' => (int)$row['first_position'],
            ],
            $rows
        );
    }

    /**
     * Planned, processed and failed entities of a job by type.
     *
     * @return array<string, array{planned: int, done: int, failed: int}>
     */
    public function getProgress(int $jobId): array
    {
        $connection = $this->getConnection();
        $rows = $connection->fetchAll(
            $connection->select()
                ->from(
                    $this->getTable(),
                    [
                        'type',
                        'planned' => 'SUM(planned_count)',
                        'done' => 'SUM(done_count)',
                        'failed' => 'SUM(failed_count)',
                    ]
                )
                ->where('job_id = ?', $jobId)
                ->group('type')
        );

        $empty = ['planned' => 0, 'done' => 0, 'failed' => 0];
        $progress = [
            self::TYPE_CATEGORY => $empty,
            self::TYPE_PRODUCT => $empty,
            self::TYPE_CUSTOMER => $empty,
            self::TYPE_ORDER => $empty,
        ];
        foreach ($rows as $row) {
            $progress[$row['type']] = [
                'planned' => (int)$row['planned'],
                'done' => (int)$row['done'],
                'failed' => (int)$row['failed'],
            ];
        }

        return $progress;
    }

    /**
     * Order breakdowns of all segments of a job, as saved JSON.
     *
     * @return list<string>
     */
    public function getSummaries(int $jobId): array
    {
        $connection = $this->getConnection();

        return array_map('strval', $connection->fetchCol(
            $connection->select()
                ->from($this->getTable(), ['summary'])
                ->where('job_id = ?', $jobId)
                ->where('type = ?', self::TYPE_ORDER)
                ->where('summary IS NOT NULL')
        ));
    }

    /**
     * @param int $segmentId
     * @param array<string, mixed> $data
     */
    public function update(int $segmentId, array $data): void
    {
        $this->getConnection()->update($this->getTable(), $data, ['segment_id = ?' => $segmentId]);
    }

    /**
     * @param array<string, mixed> $row
     * @return SegmentRow
     */
    private function normalize(array $row): array
    {
        return [
            'segment_id' => (int)$row['segment_id'],
            'job_id' => (int)$row['job_id'],
            'type' => (string)$row['type'],
            'day' => (string)$row['day'],
            'planned_count' => (int)$row['planned_count'],
            'first_position' => (int)$row['first_position'],
            'done_count' => (int)$row['done_count'],
            'failed_count' => (int)$row['failed_count'],
            'summary' => $row['summary'] === null ? null : (string)$row['summary'],
            'start_entity_id' => $row['start_entity_id'] === null ? null : (int)$row['start_entity_id'],
        ];
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
