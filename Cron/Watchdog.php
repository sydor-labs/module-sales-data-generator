<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Cron;

use Magento\MessageQueue\Model\CheckIsAvailableMessagesInQueue;
use Psr\Log\LoggerInterface;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;
use Perspective\SalesDataGenerator\Model\JobFactory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;

/**
 * Resumes unfinished jobs whose queue messages were lost, e.g. when a consumer was killed mid-batch.
 */
class Watchdog
{
    public const int STALE_MINUTES = 10;
    private const int MAX_RESUMES = 3;

    public function __construct(
        private readonly JobResource $jobResource,
        private readonly JobFactory $jobFactory,
        private readonly Scheduler $scheduler,
        private readonly CheckIsAvailableMessagesInQueue $checkIsAvailableMessages,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): void
    {
        if ($this->hasWaitingMessages()) {
            return;
        }
        $before = gmdate('Y-m-d H:i:s', time() - self::STALE_MINUTES * 60);
        foreach ($this->jobResource->getStalledJobIds($before) as $jobId) {
            $job = $this->jobFactory->create();
            $this->jobResource->load($job, $jobId);

            if ($job->getResumeCount() >= self::MAX_RESUMES && $job->getData(Job::ERROR)) {
                $this->jobResource->updateColumns($jobId, [
                    Job::STATUS => Job::STATUS_FAILED,
                    Job::FINISHED_AT => gmdate('Y-m-d H:i:s'),
                ]);
                $this->logger->error(
                    sprintf('Sales data generation job #%d failed: %s', $jobId, $job->getData(Job::ERROR))
                );
                continue;
            }

            $this->scheduler->resume($jobId, false);
            $this->logger->info(sprintf('Sales data generation job #%d stalled and was resumed.', $jobId));
        }
    }

    /**
     * Waiting messages mean consumers are busy or not running; resuming would only stack more chains.
     */
    private function hasWaitingMessages(): bool
    {
        try {
            return $this->checkIsAvailableMessages->execute(Scheduler::CONNECTION, Scheduler::QUEUE);
        } catch (\Throwable $e) {
            $this->logger->warning('Sales data generation queue cannot be checked: ' . $e->getMessage());
            return true;
        }
    }
}
