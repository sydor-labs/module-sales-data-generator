<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel\Job;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;

/**
 * Generation jobs.
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = Job::JOB_ID;

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _construct(): void
    {
        $this->_init(Job::class, JobResource::class);
    }
}
