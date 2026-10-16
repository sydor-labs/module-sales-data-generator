<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Queue;

use Perspective\SalesDataGenerator\Model\Job\Processor;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * Processes one batch of a job per message and queues the next batch while work remains.
 */
class BatchConsumer
{
    public function __construct(
        private readonly Processor $processor,
        private readonly Scheduler $scheduler
    ) {
    }

    /**
     * @param string $jobId
     * @throws \Throwable when the batch fails; the job keeps its progress and can be resumed
     */
    public function process(string $jobId): void
    {
        if ($this->processor->processBatch((int)$jobId)) {
            $this->scheduler->publish((int)$jobId);
        }
    }
}
