<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use DateInterval;
use DateTimeImmutable;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\JobFactory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;
use Perspective\SalesDataGenerator\Model\StoreContext;
use Perspective\SalesDataGenerator\Model\Timeline;

/**
 * Creates a job: its parameters with a seed and a cutoff, the categories and products to create first,
 * and the number of customers and orders per day.
 */
class Planner
{
    /**
     * How long before the period customers may have registered.
     */
    private const int REGISTRATION_LEAD_DAYS = 180;

    public function __construct(
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly Segment $segmentResource,
        private readonly Timeline $timeline,
        private readonly StoreContext $storeContext,
        private readonly Validator $validator,
        private readonly Json $json
    ) {
    }

    /**
     * @throws LocalizedException when the job would fail, e.g. orders without customers or products
     */
    public function create(Parameters $parameters): Job
    {
        $this->validator->validate($parameters);
        $parameters = $parameters->withRuntime(
            $parameters->seed ?? random_int(1, PHP_INT_MAX),
            time(),
            bin2hex(random_bytes(4))
        );
        $timezone = $this->storeContext->getTimezone($parameters->storeId);
        $leadFrom = (new DateTimeImmutable($parameters->from, $timezone))
            ->sub(new DateInterval('P' . self::REGISTRATION_LEAD_DAYS . 'D'));
        // Catalog segments sort before every customer and order segment, so the catalog exists first.
        $catalogDay = $leadFrom->sub(new DateInterval('P1D'))->format('Y-m-d');
        $leadFrom = $leadFrom->format('Y-m-d');

        $customerDays = $this->timeline->allocateDays(
            $leadFrom,
            $parameters->to,
            $parameters->customers,
            $timezone,
            (int)$parameters->cutoff,
            (int)$parameters->seed,
            Segment::TYPE_CUSTOMER
        );
        $orderDays = $this->timeline->allocateDays(
            $parameters->from,
            $parameters->to,
            $parameters->orders,
            $timezone,
            (int)$parameters->cutoff,
            (int)$parameters->seed,
            Segment::TYPE_ORDER
        );
        if ($parameters->orders > 0 && $orderDays === []) {
            throw new LocalizedException(__('The period has no time before now to place orders in.'));
        }

        $job = $this->jobFactory->create();
        $job->setData([
            Job::STATUS => Job::STATUS_QUEUED,
            Job::PARAMETERS => $this->json->serialize($parameters->toArray()),
            Job::CUSTOMERS_TOTAL => $parameters->customers,
            Job::ORDERS_TOTAL => $parameters->orders,
        ]);

        $connection = $this->jobResource->getConnection();
        $connection->beginTransaction();
        try {
            $this->jobResource->save($job);
            if ($parameters->categories > 0) {
                $this->segmentResource->insertDays(
                    (int)$job->getId(),
                    Segment::TYPE_CATEGORY,
                    [$catalogDay => $parameters->categories]
                );
            }
            if ($parameters->products > 0) {
                $this->segmentResource->insertDays(
                    (int)$job->getId(),
                    Segment::TYPE_PRODUCT,
                    [$catalogDay => $parameters->products]
                );
            }
            $this->segmentResource->insertDays((int)$job->getId(), Segment::TYPE_CUSTOMER, $customerDays);
            $this->segmentResource->insertDays((int)$job->getId(), Segment::TYPE_ORDER, $orderDays);
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }

        return $job;
    }
}
