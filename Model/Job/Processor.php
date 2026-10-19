<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use DateTimeImmutable;
use DateTimeZone;
use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Model\Customer;
use Magento\Framework\App\Area;
use Magento\Framework\App\State;
use Magento\Framework\Indexer\IndexerRegistry;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Store\Api\Data\StoreInterface;
use Perspective\SalesDataGenerator\Model\BuyerSelector;
use Perspective\SalesDataGenerator\Model\CatalogGenerator;
use Perspective\SalesDataGenerator\Model\Config;
use Perspective\SalesDataGenerator\Model\CustomerGenerator;
use Perspective\SalesDataGenerator\Model\CustomerLocator;
use Perspective\SalesDataGenerator\Model\DeadlockRetry;
use Perspective\SalesDataGenerator\Model\GenerationContext;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\JobCategoryCreator;
use Perspective\SalesDataGenerator\Model\JobFactory;
use Perspective\SalesDataGenerator\Model\OrderGenerator;
use Perspective\SalesDataGenerator\Model\ProductPool;
use Perspective\SalesDataGenerator\Model\Random;
use Perspective\SalesDataGenerator\Model\RuntimeCacheCleaner;
use Perspective\SalesDataGenerator\Model\ResourceModel\Backdater;
use Perspective\SalesDataGenerator\Model\ResourceModel\GeneratedEntities;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;
use Perspective\SalesDataGenerator\Model\ResourceModel\JobCategory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;
use Perspective\SalesDataGenerator\Model\StatisticsRefresher;
use Perspective\SalesDataGenerator\Model\StoreContext;
use Perspective\SalesDataGenerator\Model\Timeline;
use Psr\Log\LoggerInterface;

/**
 * Runs one batch of a job: claims ready segments in order (catalog first, then customers and orders by day)
 * until the batch size is used.
 *
 * @phpstan-import-type SegmentRow from Segment
 * @phpstan-import-type Summary from Progress
 */
class Processor
{
    private const string LOCK_PREFIX = 'perspective_sdg_segment_';
    private const int CANDIDATES = 50;

    /**
     * How long a worker waits when every unfinished segment is taken or waits for customers.
     */
    private const int WAIT_SECONDS = 2;

    /**
     * @var int
     */
    private int $sinceCacheClean = 0;

    /**
     * Jobs whose category positions were looked up by URL key in this process.
     *
     * @var array<int, true>
     */
    private array $backfilledJobs = [];

    public function __construct(
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly Segment $segmentResource,
        private readonly GeneratedEntities $generatedEntities,
        private readonly Timeline $timeline,
        private readonly CatalogGenerator $catalogGenerator,
        private readonly CustomerGenerator $customerGenerator,
        private readonly CustomerLocator $customerLocator,
        private readonly CustomerRepositoryInterface $customerRepository,
        private readonly BuyerSelector $buyerSelector,
        private readonly OrderGenerator $orderGenerator,
        private readonly ProductPool $productPool,
        private readonly Backdater $backdater,
        private readonly StatisticsRefresher $statisticsRefresher,
        private readonly IndexerRegistry $indexerRegistry,
        private readonly StoreContext $storeContext,
        private readonly Config $config,
        private readonly Random $random,
        private readonly Progress $progress,
        private readonly GenerationContext $generationContext,
        private readonly RuntimeCacheCleaner $runtimeCacheCleaner,
        private readonly LockManagerInterface $lockManager,
        private readonly State $appState,
        private readonly Json $json,
        private readonly JobCategory $jobCategory,
        private readonly JobCategoryCreator $jobCategoryCreator,
        private readonly DeadlockRetry $deadlockRetry,
        private readonly LoggerInterface $logger,
        private readonly int $cleanCachesEvery = 20
    ) {
    }

    /**
     * Process the next batch of a job.
     *
     * @param int $jobId
     * @param callable(): void|null $onProgress called after each customer and order
     * @return bool true while the job is unfinished, so the caller keeps its chain of batches going
     * @throws \Throwable when the batch fails; the error is saved on the job first
     */
    public function processBatch(int $jobId, ?callable $onProgress = null): bool
    {
        try {
            return $this->appState->emulateAreaCode(
                Area::AREA_ADMINHTML,
                fn (): bool => $this->generationContext->run(fn (): bool => $this->runBatch($jobId, $onProgress))
            );
        } catch (\Throwable $e) {
            if ($this->deadlockRetry->isLockConflict($e)) {
                // E.g. an indexer holding catalog tables longer than the retries wait. The chain goes on and
                // the unfinished segment is taken again; what the batch created is found by its tag.
                $this->logger->warning(
                    sprintf('Job #%d, pid %d: batch postponed: %s', $jobId, getmypid(), $e->getMessage())
                );
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                sleep(self::WAIT_SECONDS);
                return true;
            }
            $this->jobResource->updateColumns($jobId, [Job::ERROR => $e->getMessage()]);
            throw $e;
        } finally {
            $this->runtimeCacheCleaner->clean();
        }
    }

    /**
     * @param int $jobId
     * @param callable(): void|null $onProgress
     * @return bool
     */
    private function runBatch(int $jobId, ?callable $onProgress): bool
    {
        $job = $this->jobFactory->create();
        $this->jobResource->load($job, $jobId);
        if (!$job->getId() || $job->isFinished() || $job->getStatus() === Job::STATUS_FINISHING) {
            return false;
        }
        $parameters = Parameters::fromArray($this->json->unserialize((string)$job->getData(Job::PARAMETERS)));

        $segment = $this->claimSegment($jobId, $parameters);
        if ($segment === null) {
            if (!$this->segmentResource->hasUnfinished($jobId)) {
                $this->finish($job, $parameters);
                return false;
            }
            // phpcs:ignore Magento2.Functions.DiscouragedFunction
            sleep(self::WAIT_SECONDS);
            return true;
        }

        $this->jobResource->transition($jobId, [Job::STATUS_QUEUED], Job::STATUS_RUNNING);
        $store = $this->storeContext->activate($parameters->storeId);
        $timezone = $this->storeContext->getTimezone($parameters->storeId);
        $budget = $this->config->getBatchSize();
        $ordersPrepared = false;
        $startedAt = microtime(true);
        $processedByType = [];
        // Small days are processed together, so a batch is not mostly setup.
        while ($segment !== null) {
            try {
                if ($segment['type'] === Segment::TYPE_CATEGORY) {
                    $processed = $this->createCategories(
                        $jobId,
                        $segment,
                        $store,
                        $parameters,
                        $budget,
                        $onProgress
                    );
                } elseif ($segment['type'] === Segment::TYPE_PRODUCT) {
                    $processed = $this->createProducts(
                        $jobId,
                        $segment,
                        $store,
                        $parameters,
                        $budget,
                        $onProgress
                    );
                } elseif ($segment['type'] === Segment::TYPE_CUSTOMER) {
                    $processed = $this->registerCustomers(
                        $jobId,
                        $segment,
                        $store,
                        $parameters,
                        $timezone,
                        $budget,
                        $onProgress
                    );
                } else {
                    if (!$ordersPrepared) {
                        $this->orderGenerator->loadSettings();
                        $this->productPool->load(
                            (int)$store->getId(),
                            (int)$parameters->seed,
                            (string)$parameters->token
                        );
                        $ordersPrepared = true;
                    }
                    $processed = $this->placeOrders(
                        $jobId,
                        $segment,
                        $store,
                        $parameters,
                        $timezone,
                        $budget,
                        $onProgress
                    );
                }
            } finally {
                $this->lockManager->unlock(self::LOCK_PREFIX . $segment['segment_id']);
            }
            if ($processed === 0) {
                throw new \LogicException(
                    sprintf('Segment %d has no moments left to process.', $segment['segment_id'])
                );
            }
            $budget -= $processed;
            $processedByType[$segment['type']] = ($processedByType[$segment['type']] ?? 0) + $processed;
            $this->jobResource->updateColumns($jobId, [
                Job::HEARTBEAT_AT => $this->now(),
                Job::RESUME_COUNT => 0,
            ]);
            $segment = $budget > 0 ? $this->claimSegment($jobId, $parameters) : null;
        }
        $this->logBatch($jobId, $processedByType, $startedAt);

        return true;
    }

    /**
     * Log what a batch processed, how long it took and the worker's memory, to tune batch sizes.
     *
     * @param int $jobId
     * @param array<string, int> $processedByType
     * @param float $startedAt
     */
    private function logBatch(int $jobId, array $processedByType, float $startedAt): void
    {
        $processed = [];
        foreach ($processedByType as $type => $count) {
            $processed[] = $type . ' ' . $count;
        }
        $this->logger->info(sprintf(
            'Job #%d, pid %d: %s in %.1f s; memory %.1f MB, peak %.1f MB',
            $jobId,
            getmypid(),
            implode(', ', $processed),
            microtime(true) - $startedAt,
            memory_get_usage() / 1048576,
            memory_get_peak_usage() / 1048576
        ));
    }

    /**
     * Lock the first unfinished segment that is ready and no other worker holds.
     *
     * @return SegmentRow|null
     */
    private function claimSegment(int $jobId, Parameters $parameters): ?array
    {
        $candidates = $this->segmentResource->getUnfinished(
            $jobId,
            self::CANDIDATES,
            $this->getNotReadyFrom($jobId, $parameters)
        );
        foreach ($candidates as $candidate) {
            if (!$this->isReady($jobId, $candidate)) {
                continue;
            }
            $lockName = self::LOCK_PREFIX . $candidate['segment_id'];
            if (!$this->lockManager->lock($lockName, 0)) {
                continue;
            }
            // Re-read under the lock: another worker may have finished it meanwhile.
            $segment = $this->segmentResource->get($candidate['segment_id']);
            if ($segment !== null && $segment['done_count'] < $segment['planned_count']) {
                return $segment;
            }
            $this->lockManager->unlock($lockName);
        }

        return null;
    }

    /**
     * Segments that cannot start yet whatever their day: categories other than departments wait for every
     * department, products for every category, and orders for the whole catalog.
     *
     * @return array<string, int> type => first position from which its segments are not ready
     */
    private function getNotReadyFrom(int $jobId, Parameters $parameters): array
    {
        $departments = CatalogGenerator::departmentCount($parameters->categories);
        $notReadyFrom = [];
        if (!$this->segmentResource->isDoneBefore($jobId, Segment::TYPE_CATEGORY, $departments)) {
            $notReadyFrom[Segment::TYPE_CATEGORY] = $departments;
        }
        if (!$this->segmentResource->areDone($jobId, [Segment::TYPE_CATEGORY])) {
            $notReadyFrom[Segment::TYPE_PRODUCT] = 0;
            $notReadyFrom[Segment::TYPE_ORDER] = 0;
        } elseif (!$this->segmentResource->areDone($jobId, [Segment::TYPE_PRODUCT])) {
            $notReadyFrom[Segment::TYPE_ORDER] = 0;
        }

        return $notReadyFrom;
    }

    /**
     * Orders also wait for every customer registered up to their day.
     *
     * @param int $jobId
     * @param SegmentRow $segment
     * @return bool
     */
    private function isReady(int $jobId, array $segment): bool
    {
        return $segment['type'] !== Segment::TYPE_ORDER
            || $this->segmentResource->areCustomersDoneUntil($jobId, $segment['day']);
    }

    /**
     * Create planned categories: departments under the store root first, then the others under them.
     *
     * @param int $jobId
     * @param SegmentRow $segment
     * @param StoreInterface $store
     * @param Parameters $parameters
     * @param int $budget
     * @param callable(): void|null $onProgress
     * @return int categories processed
     */
    private function createCategories(
        int $jobId,
        array $segment,
        StoreInterface $store,
        Parameters $parameters,
        int $budget,
        ?callable $onProgress
    ): int {
        $token = (string)$parameters->token;
        $rootId = $this->storeContext->getRootCategoryId((int)$store->getId());
        $departments = CatalogGenerator::departmentCount($parameters->categories);
        $count = min($budget, $segment['planned_count'] - $segment['done_count']);
        $positions = range(
            $segment['first_position'] + $segment['done_count'],
            $segment['first_position'] + $segment['done_count'] + $count - 1
        );
        // Also the categories created before an interruption, so they are neither repeated nor orphaned.
        $created = $this->jobCategory->getRange($jobId, $positions[0], $positions[$count - 1]);
        $parentIds = $this->categoryIds($jobId, $token, array_values(array_unique(array_map(
            static fn (int $position): int => $position % $departments,
            array_filter($positions, static fn (int $position): bool => $position >= $departments)
        ))));

        $this->storeContext->activateAdmin();
        try {
            foreach ($positions as $position) {
                if (!isset($created[$position])) {
                    $isDepartment = $position < $departments;
                    // A job planned before departments got segments of their own creates them in this batch.
                    $parentId = $isDepartment
                        ? $rootId
                        : ($created[$position % $departments] ?? $parentIds[$position % $departments] ?? null);
                    if ($parentId === null) {
                        throw new \RuntimeException(
                            sprintf('Category at position %d was not found.', $position % $departments)
                        );
                    }
                    $created[$position] = $this->jobCategoryCreator->create(
                        $jobId,
                        $token,
                        (int)$parameters->seed,
                        $position,
                        $parentId,
                        $isDepartment
                    );
                }
                $this->afterEntity($onProgress);
            }
        } finally {
            $this->storeContext->activate((int)$store->getId());
        }

        $this->segmentResource->update($segment['segment_id'], ['done_count' => $segment['done_count'] + $count]);

        return $count;
    }

    /**
     * Create planned simple products in the job's categories, skipping those created before an interruption.
     *
     * @param int $jobId
     * @param SegmentRow $segment
     * @param StoreInterface $store
     * @param Parameters $parameters
     * @param int $budget
     * @param callable(): void|null $onProgress
     * @return int products processed
     */
    private function createProducts(
        int $jobId,
        array $segment,
        StoreInterface $store,
        Parameters $parameters,
        int $budget,
        ?callable $onProgress
    ): int {
        $token = (string)$parameters->token;
        $count = min($budget, $segment['planned_count'] - $segment['done_count']);
        $categoryIds = fn (array $positions): array => $this->categoryIds($jobId, $token, $positions);

        $this->storeContext->activateAdmin();
        try {
            for ($index = 0; $index < $count; $index++) {
                $position = $segment['first_position'] + $segment['done_count'] + $index;
                $this->deadlockRetry->execute(function () use (
                    $token,
                    $position,
                    $store,
                    $parameters,
                    $categoryIds
                ): void {
                    // Also on a repeated attempt, in case the failure came after the product was saved.
                    $sku = CatalogGenerator::sku($token, $position);
                    if ($this->generatedEntities->findProductIdBySku($sku) !== null) {
                        return;
                    }
                    $this->random->seed(Random::seedFor((int)$parameters->seed, Segment::TYPE_PRODUCT, $position));
                    $this->catalogGenerator->createProduct(
                        $token,
                        $position,
                        (int)$store->getWebsiteId(),
                        $parameters->categories,
                        $categoryIds
                    );
                });
                $this->afterEntity($onProgress);
            }
        } finally {
            $this->storeContext->activate((int)$store->getId());
        }

        $this->segmentResource->update($segment['segment_id'], ['done_count' => $segment['done_count'] + $count]);

        return $count;
    }

    /**
     * Category IDs of the job at the given positions. Jobs planned before the positions were recorded
     * have their categories looked up by URL key, once per process.
     *
     * @param int $jobId
     * @param string $token
     * @param list<int> $positions
     * @return array<int, int> category ID by position; deleted categories are left out
     */
    private function categoryIds(int $jobId, string $token, array $positions): array
    {
        $ids = $this->jobCategory->getIds($jobId, $positions);
        if (count($ids) < count(array_unique($positions)) && !isset($this->backfilledJobs[$jobId])) {
            $this->jobCategory->saveMultiple($jobId, $this->generatedEntities->getJobCategoryIds($token));
            $this->backfilledJobs[$jobId] = true;
            $ids = $this->jobCategory->getIds($jobId, $positions);
        }

        return $ids;
    }

    /**
     * Register planned customers of the segment, reusing those created before an interruption.
     *
     * @param int $jobId
     * @param SegmentRow $segment
     * @param StoreInterface $store
     * @param Parameters $parameters
     * @param DateTimeZone $timezone
     * @param int $budget
     * @param callable(): void|null $onProgress
     * @return int customers processed
     */
    private function registerCustomers(
        int $jobId,
        array $segment,
        StoreInterface $store,
        Parameters $parameters,
        DateTimeZone $timezone,
        int $budget,
        ?callable $onProgress
    ): int {
        $seed = (int)$parameters->seed;
        $token = (string)$parameters->token;
        $moments = $this->moments($segment, $budget, $timezone, $parameters, Segment::TYPE_CUSTOMER);
        $websiteId = (int)$store->getWebsiteId();
        $recovering = true;
        $customerIds = [];

        foreach ($moments as $index => $moment) {
            $position = $segment['first_position'] + $segment['done_count'] + $index;
            $createdAt = new DateTimeImmutable('@' . $moment);
            if ($recovering) {
                $existing = $this->customerLocator->findIds($seed, $token, [$position], $websiteId)[$position] ?? null;
                if ($existing !== null) {
                    $this->backdater->backdateCustomer($existing, $createdAt);
                    $customerIds[] = $existing;
                    continue;
                }
                // Customers are created in order, so nothing after the first missing one exists.
                $recovering = false;
            }
            $this->random->seed(CustomerLocator::seedOf($seed, $position));
            $customer = $this->customerGenerator->create($store, $createdAt, CustomerLocator::tag($token, $position));
            $customerIds[] = (int)$customer->getId();
            $this->afterEntity($onProgress);
        }

        $this->segmentResource->update($segment['segment_id'], [
            'done_count' => $segment['done_count'] + count($moments),
        ]);
        if ($customerIds !== []) {
            // The customer grid indexer may not be updated on save.
            $this->indexerRegistry->get(Customer::CUSTOMER_GRID_INDEXER_ID)->reindexList($customerIds);
        }

        return count($moments);
    }

    /**
     * Place planned orders of the segment; a failed order is counted and does not stop the batch.
     *
     * @param int $jobId
     * @param SegmentRow $segment
     * @param StoreInterface $store
     * @param Parameters $parameters
     * @param DateTimeZone $timezone
     * @param int $budget
     * @param callable(): void|null $onProgress
     * @return int orders processed
     */
    private function placeOrders(
        int $jobId,
        array $segment,
        StoreInterface $store,
        Parameters $parameters,
        DateTimeZone $timezone,
        int $budget,
        ?callable $onProgress
    ): int {
        $seed = (int)$parameters->seed;
        $token = (string)$parameters->token;
        if ($segment['start_entity_id'] === null) {
            $segment['start_entity_id'] = $this->generatedEntities->getMaxOrderId();
            $this->segmentResource->update(
                $segment['segment_id'],
                ['start_entity_id' => $segment['start_entity_id']]
            );
        }
        $existing = array_flip($this->generatedEntities->findOrderPositions(
            OrderGenerator::getOrderTagPrefix($token),
            $segment['start_entity_id']
        ));
        $moments = $this->moments($segment, $budget, $timezone, $parameters, Segment::TYPE_ORDER);
        $this->buyerSelector->prepare($jobId, $seed, (int)$parameters->cutoff, $timezone);

        // First pass only picks the buyers, so all their IDs come from one query.
        $buyers = [];
        foreach ($moments as $index => $moment) {
            $position = $segment['first_position'] + $segment['done_count'] + $index;
            if (!isset($existing[$position])) {
                $buyers[$position] = $this->selectBuyer($seed, $position, $moment, $parameters);
            }
        }
        $customerIds = $this->customerLocator->findIds(
            $seed,
            $token,
            array_values(array_filter($buyers, static fn (?int $buyer): bool => $buyer !== null)),
            (int)$store->getWebsiteId()
        );

        /** @var Summary $summary */
        $summary = $this->json->unserialize($segment['summary'] ?? '[]') ?: [];
        $failed = $segment['failed_count'];
        foreach ($moments as $index => $moment) {
            $position = $segment['first_position'] + $segment['done_count'] + $index;
            if (isset($existing[$position])) {
                continue;
            }
            try {
                // Re-selecting keeps the order's random sequence the same as in any other run.
                $buyer = $this->selectBuyer($seed, $position, $moment, $parameters);
                $customer = null;
                if ($buyer !== null) {
                    if (!isset($customerIds[$buyer])) {
                        throw new \RuntimeException(sprintf('Customer at position %d was not found.', $buyer));
                    }
                    $customer = $this->customerRepository->getById($customerIds[$buyer]);
                }
                $order = $this->orderGenerator->create(
                    $store,
                    new DateTimeImmutable('@' . $moment),
                    $customer,
                    CustomerLocator::tag($token, $position),
                    OrderGenerator::getOrderTagPrefix($token) . $position
                );
                $this->count($summary, Progress::SUMMARY_STATUS, $order->status);
                $this->count($summary, Progress::SUMMARY_PAYMENT, $order->paymentMethod);
                $this->count($summary, Progress::SUMMARY_SHIPPING, $order->shippingMethod);
            } catch (\Throwable $e) {
                $failed++;
                $this->count($summary, Progress::SUMMARY_ERRORS, $e->getMessage());
            }
            $this->afterEntity($onProgress);
        }

        $this->segmentResource->update($segment['segment_id'], [
            'done_count' => $segment['done_count'] + count($moments),
            'failed_count' => $failed,
            'summary' => $this->json->serialize($summary),
        ]);

        return count($moments);
    }

    /**
     * Report progress and empty Magento caches every few entities.
     *
     * @param callable(): void|null $onProgress
     */
    private function afterEntity(?callable $onProgress): void
    {
        $onProgress && $onProgress();
        if (++$this->sinceCacheClean >= $this->cleanCachesEvery) {
            $this->runtimeCacheCleaner->clean();
            $this->sinceCacheClean = 0;
        }
    }

    /**
     * Seed the order's random sequence and pick its buyer.
     *
     * @return int|null customer position, or null for a guest
     */
    private function selectBuyer(int $seed, int $position, int $moment, Parameters $parameters): ?int
    {
        $this->random->seed(Random::seedFor($seed, Segment::TYPE_ORDER, $position));

        return $this->buyerSelector->select($this->buyerSelector->registeredAt($moment), $parameters->guestRatio);
    }

    /**
     * Moments of the next unprocessed part of the segment.
     *
     * @param SegmentRow $segment
     * @param int $budget
     * @param DateTimeZone $timezone
     * @param Parameters $parameters
     * @param string $type
     * @return list<int>
     */
    private function moments(
        array $segment,
        int $budget,
        DateTimeZone $timezone,
        Parameters $parameters,
        string $type
    ): array {
        return $this->timeline->moments(
            $segment['day'],
            $segment['planned_count'],
            $segment['done_count'],
            min($budget, $segment['planned_count'] - $segment['done_count']),
            $timezone,
            (int)$parameters->cutoff,
            (int)$parameters->seed,
            $type
        );
    }

    /**
     * Refresh statistics and close the job.
     */
    private function finish(Job $job, Parameters $parameters): void
    {
        $jobId = (int)$job->getId();
        $statuses = [Job::STATUS_QUEUED, Job::STATUS_RUNNING];
        if (!$this->jobResource->transition($jobId, $statuses, Job::STATUS_FINISHING)) {
            return;
        }
        if (!$parameters->skipStatistics) {
            $this->statisticsRefresher->refresh();
        }
        $this->jobResource->updateColumns($jobId, [
            Job::STATUS => Job::STATUS_DONE,
            Job::SUMMARY => $this->json->serialize(
                $this->progress->merge($this->segmentResource->getSummaries($jobId))
            ),
            Job::ERROR => null,
            Job::FINISHED_AT => $this->now(),
        ]);
    }

    /**
     * @param array<string, array<string, int>> $summary
     * @param string $aspect
     * @param string $value
     */
    private function count(array &$summary, string $aspect, string $value): void
    {
        $summary[$aspect][$value] = ($summary[$aspect][$value] ?? 0) + 1;
    }

    private function now(): string
    {
        return gmdate('Y-m-d H:i:s');
    }
}
