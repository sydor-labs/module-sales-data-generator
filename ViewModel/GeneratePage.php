<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\ViewModel;

use Magento\Backend\Model\Session;
use Magento\Backend\Model\UrlInterface;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\StoreManagerInterface;
use Perspective\SalesDataGenerator\Cron\Watchdog;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\Job\Parameters;
use Perspective\SalesDataGenerator\Model\Job\ParametersParser;
use Perspective\SalesDataGenerator\Model\Job\Progress;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job\CollectionFactory;

/**
 * Generation options form and recent jobs.
 *
 * @phpstan-type JobRow array{id: int, created_at: string, status: string, stalled: bool, percent: int,
 *     catalog: string, customers: string, orders: string, failed: int, statuses: array<string, int>,
 *     error: string, can_resume: bool}
 */
class GeneratePage implements ArgumentInterface
{
    private const int JOBS_LIMIT = 20;

    public function __construct(
        private readonly CollectionFactory $collectionFactory,
        private readonly StoreManagerInterface $storeManager,
        private readonly UrlInterface $urlBuilder,
        private readonly TimezoneInterface $timezone,
        private readonly Session $session,
        private readonly Progress $progress
    ) {
    }

    /**
     * Values entered before a failed submit, otherwise the defaults.
     *
     * @return array<string, mixed>
     */
    public function getFormValues(): array
    {
        $defaults = [
            Parameters::CUSTOMERS => '',
            Parameters::ORDERS => '',
            Parameters::CATEGORIES => '',
            Parameters::PRODUCTS => '',
            Parameters::FROM => (new \DateTimeImmutable('-1 year'))->format('Y-m-d'),
            Parameters::TO => (new \DateTimeImmutable('today'))->format('Y-m-d'),
            Parameters::STORE => (int)$this->storeManager->getDefaultStoreView()?->getId(),
            Parameters::GUEST_RATIO => ParametersParser::DEFAULT_GUEST_RATIO,
            Parameters::SEED => '',
            Parameters::SKIP_STATISTICS => false,
        ];
        $submitted = $this->session->getData('sales_data_generator_form', true);

        return is_array($submitted) ? array_merge($defaults, $submitted) : $defaults;
    }

    /**
     * @return array<int, string> store view ID => "Website / Store View"
     */
    public function getStoreOptions(): array
    {
        $options = [];
        foreach ($this->storeManager->getStores() as $store) {
            $options[(int)$store->getId()] = sprintf(
                '%s / %s (%s)',
                $this->storeManager->getWebsite($store->getWebsiteId())->getName(),
                $store->getName(),
                $store->getCode()
            );
        }

        return $options;
    }

    public function getSaveUrl(): string
    {
        return $this->urlBuilder->getUrl('sales_data_generator/generate/save');
    }

    public function getResumeUrl(int $jobId): string
    {
        return $this->urlBuilder->getUrl('sales_data_generator/job/resume', ['job_id' => $jobId]);
    }

    public function getConsumerCommand(): string
    {
        return 'bin/magento queue:consumers:start ' . Scheduler::CONSUMER;
    }

    /**
     * @return list<JobRow>
     */
    public function getJobs(): array
    {
        $collection = $this->collectionFactory->create()
            ->setOrder(Job::JOB_ID, 'DESC')
            ->setPageSize(self::JOBS_LIMIT);

        $rows = [];
        /** @var Job $job */
        foreach ($collection as $job) {
            $rows[] = $this->toRow($job);
        }

        return $rows;
    }

    /**
     * @return JobRow
     */
    private function toRow(Job $job): array
    {
        $progress = $this->progress->get($job);
        $total = $progress['total'];
        $done = $progress['done'];
        $heartbeat = (string)($job->getData(Job::HEARTBEAT_AT) ?: $job->getData(Job::CREATED_AT));
        $unfinished = [Job::STATUS_QUEUED, Job::STATUS_RUNNING, Job::STATUS_FINISHING];
        $stalled = in_array($job->getStatus(), $unfinished, true)
            && $heartbeat !== ''
            && strtotime($heartbeat . ' UTC') < time() - Watchdog::STALE_MINUTES * 60;
        $statuses = $this->progress->getSummary($job)[Progress::SUMMARY_STATUS] ?? [];
        arsort($statuses);

        return [
            'id' => (int)$job->getId(),
            'created_at' => $this->timezone->formatDateTime((string)$job->getData(Job::CREATED_AT)),
            'status' => $job->getStatus(),
            'stalled' => $stalled,
            'percent' => $total > 0 ? (int)floor($done * 100 / $total) : 100,
            'catalog' => $progress['categories_total'] + $progress['products_total'] > 0
                ? sprintf(
                    '%d / %d categories, %d / %d products',
                    $progress['categories_done'],
                    $progress['categories_total'],
                    $progress['products_done'],
                    $progress['products_total']
                )
                : '',
            'customers' => $progress['customers_done'] . ' / ' . $job->getCustomersTotal(),
            'orders' => $progress['orders_done'] . ' / ' . $job->getOrdersTotal(),
            'failed' => $progress['orders_failed'],
            'statuses' => $statuses,
            'error' => (string)$job->getData(Job::ERROR),
            'can_resume' => $stalled || $job->getStatus() === Job::STATUS_FAILED
                || ($job->getStatus() === Job::STATUS_RUNNING && (string)$job->getData(Job::ERROR) !== ''),
        ];
    }
}
