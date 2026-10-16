<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Reports\Model\ResourceModel\Report\AbstractReport;

/**
 * Rebuilds the lifetime statistics of the core sales reports, as "Refresh Lifetime Statistics" in the admin does.
 */
class StatisticsRefresher
{
    /**
     * @param array<string, AbstractReport> $reports report resource models keyed by report code
     */
    public function __construct(
        private readonly array $reports = []
    ) {
    }

    /**
     * @param callable(string): void|null $onReport called with each report code before it is rebuilt
     */
    public function refresh(?callable $onReport = null): void
    {
        foreach ($this->reports as $code => $report) {
            if ($onReport !== null) {
                $onReport($code);
            }
            $report->aggregate();
        }
    }
}
