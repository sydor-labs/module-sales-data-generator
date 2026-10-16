<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\MessageQueue\PublisherInterface;
use Perspective\SalesDataGenerator\Model\Config;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\JobFactory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;

/**
 * Hands job batches to the queue consumers, one chain of messages per parallel worker.
 */
class Scheduler
{
    public const string TOPIC = 'perspective.sales_data_generator.batch';
    public const string QUEUE = 'perspective.sales_data_generator.batch';
    public const string CONNECTION = 'amqp';
    public const string CONSUMER = 'salesDataGenerator';

    public function __construct(
        private readonly PublisherInterface $publisher,
        private readonly Config $config,
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource
    ) {
    }

    /**
     * Start a planned job with as many message chains as there are configured workers.
     */
    public function start(int $jobId): void
    {
        // Lets the watchdog notice a job whose messages were lost before any worker started it.
        $this->jobResource->updateColumns($jobId, [Job::HEARTBEAT_AT => gmdate('Y-m-d H:i:s')]);
        for ($worker = 0; $worker < $this->config->getWorkers(); $worker++) {
            $this->publish($jobId);
        }
    }

    /**
     * Queue the next batch of a job.
     */
    public function publish(int $jobId): void
    {
        $this->publisher->publish(self::TOPIC, (string)$jobId);
    }

    /**
     * Restart the message chains of an unfinished or failed job.
     *
     * @param int $jobId
     * @param bool $manual a manual resume clears the error and the automatic resume count
     * @throws LocalizedException when the job does not exist or is already done
     */
    public function resume(int $jobId, bool $manual): void
    {
        $this->reopen($jobId, $manual);
        $this->start($jobId);
    }

    /**
     * Reopen a job to be continued in the current process, e.g. by the resume command.
     *
     * @throws LocalizedException when the job does not exist or is already done
     */
    public function markResumed(int $jobId): void
    {
        $this->reopen($jobId, true);
    }

    /**
     * @throws LocalizedException
     */
    private function reopen(int $jobId, bool $manual): void
    {
        $job = $this->jobFactory->create();
        $this->jobResource->load($job, $jobId);
        if (!$job->getId()) {
            throw new LocalizedException(__('Job #%1 does not exist.', $jobId));
        }
        if ($job->getStatus() === Job::STATUS_DONE) {
            throw new LocalizedException(__('Job #%1 is already done.', $jobId));
        }

        $data = [
            Job::STATUS => Job::STATUS_RUNNING,
            // Gives the consumer time to pick the batch up before the watchdog looks at the job again.
            Job::HEARTBEAT_AT => gmdate('Y-m-d H:i:s'),
            Job::FINISHED_AT => null,
            Job::RESUME_COUNT => $manual ? 0 : $job->getResumeCount() + 1,
        ];
        if ($manual) {
            $data[Job::ERROR] = null;
        }
        $this->jobResource->updateColumns($jobId, $data);
    }
}
