<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use Magento\Framework\Serialize\Serializer\Json;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;

/**
 * Progress and order breakdown of a job, collected from its segments.
 *
 * @phpstan-type Summary array<string, array<string, int>>
 */
class Progress
{
    public const string SUMMARY_STATUS = 'status';
    public const string SUMMARY_PAYMENT = 'payment';
    public const string SUMMARY_SHIPPING = 'shipping';
    public const string SUMMARY_ERRORS = 'errors';

    public function __construct(
        private readonly Segment $segmentResource,
        private readonly Json $json
    ) {
    }

    /**
     * @return array{categories_total: int, categories_done: int, products_total: int, products_done: int,
     *     customers_done: int, orders_done: int, orders_failed: int, total: int, done: int}
     */
    public function get(Job $job): array
    {
        $progress = $this->segmentResource->getProgress((int)$job->getId());

        return [
            'categories_total' => $progress[Segment::TYPE_CATEGORY]['planned'],
            'categories_done' => $progress[Segment::TYPE_CATEGORY]['done'],
            'products_total' => $progress[Segment::TYPE_PRODUCT]['planned'],
            'products_done' => $progress[Segment::TYPE_PRODUCT]['done'],
            'customers_done' => $progress[Segment::TYPE_CUSTOMER]['done'],
            'orders_done' => $progress[Segment::TYPE_ORDER]['done'],
            'orders_failed' => $progress[Segment::TYPE_ORDER]['failed'],
            'total' => array_sum(array_column($progress, 'planned')),
            'done' => array_sum(array_column($progress, 'done')),
        ];
    }

    /**
     * Saved breakdown of a finished job, otherwise the sum of its segments so far.
     *
     * @return Summary
     */
    public function getSummary(Job $job): array
    {
        $saved = (string)$job->getData(Job::SUMMARY);
        if ($saved !== '') {
            return $this->json->unserialize($saved) ?: [];
        }

        return $this->merge($this->segmentResource->getSummaries((int)$job->getId()));
    }

    /**
     * @param list<string> $summaries JSON breakdowns
     * @return Summary
     */
    public function merge(array $summaries): array
    {
        $merged = [];
        foreach ($summaries as $summary) {
            foreach ($this->json->unserialize($summary) ?: [] as $aspect => $counts) {
                foreach ($counts as $value => $count) {
                    $merged[$aspect][$value] = ($merged[$aspect][$value] ?? 0) + (int)$count;
                }
            }
        }

        return $merged;
    }
}
