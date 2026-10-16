<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\Model\AbstractModel;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;

/**
 * A generation run: its parameters and state; progress is kept in its daily segments.
 */
class Job extends AbstractModel
{
    public const string STATUS_QUEUED = 'queued';
    public const string STATUS_RUNNING = 'running';
    public const string STATUS_FINISHING = 'finishing';
    public const string STATUS_DONE = 'done';
    public const string STATUS_FAILED = 'failed';

    public const string JOB_ID = 'job_id';
    public const string STATUS = 'status';
    public const string PARAMETERS = 'parameters';
    public const string CUSTOMERS_TOTAL = 'customers_total';
    public const string ORDERS_TOTAL = 'orders_total';
    public const string SUMMARY = 'summary';
    public const string ERROR = 'error';
    public const string RESUME_COUNT = 'resume_count';
    public const string HEARTBEAT_AT = 'heartbeat_at';
    public const string CREATED_AT = 'created_at';
    public const string FINISHED_AT = 'finished_at';

    /**
     * @var string
     */
    protected $_eventPrefix = 'perspective_sales_data_generator_job';

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(JobResource::class);
    }

    public function getStatus(): string
    {
        return (string)$this->getData(self::STATUS);
    }

    public function getCustomersTotal(): int
    {
        return (int)$this->getData(self::CUSTOMERS_TOTAL);
    }

    public function getOrdersTotal(): int
    {
        return (int)$this->getData(self::ORDERS_TOTAL);
    }

    public function getResumeCount(): int
    {
        return (int)$this->getData(self::RESUME_COUNT);
    }

    public function isFinished(): bool
    {
        return in_array($this->getStatus(), [self::STATUS_DONE, self::STATUS_FAILED], true);
    }
}
