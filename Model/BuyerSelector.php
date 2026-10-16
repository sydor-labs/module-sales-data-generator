<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use DateTimeImmutable;
use DateTimeZone;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;

/**
 * Picks who places an order: a guest or a customer registered before the order, by position.
 */
class BuyerSelector
{
    private const int LOYAL_CUSTOMER_EVERY = 10;
    private const float LOYAL_ORDER_SHARE = 0.5;
    private const int MAX_TRIES = 30;

    /**
     * @var list<array{day: string, planned_count: int, first_position: int}>
     */
    private array $days = [];

    /**
     * @var int
     */
    private int $total = 0;

    /**
     * @var int
     */
    private int $seed = 0;

    /**
     * @var int
     */
    private int $cutoff = 0;

    /**
     * @var DateTimeZone|null
     */
    private ?DateTimeZone $timezone = null;

    public function __construct(
        private readonly Segment $segmentResource,
        private readonly Timeline $timeline,
        private readonly Random $random
    ) {
    }

    public function prepare(int $jobId, int $seed, int $cutoff, DateTimeZone $timezone): void
    {
        $this->days = $this->segmentResource->getCustomerDays($jobId);
        $last = end($this->days);
        $this->total = $last === false ? 0 : $last['first_position'] + $last['planned_count'];
        $this->seed = $seed;
        $this->cutoff = $cutoff;
        $this->timezone = $timezone;
    }

    /**
     * How many customers are registered at the given time.
     */
    public function registeredAt(int $timestamp): int
    {
        $day = (new DateTimeImmutable('@' . $timestamp))->setTimezone($this->timezone)->format('Y-m-d');

        $low = 0;
        $high = count($this->days);
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($this->days[$middle]['day'] < $day) {
                $low = $middle + 1;
            } else {
                $high = $middle;
            }
        }
        if ($low === count($this->days)) {
            return $this->total;
        }

        $segment = $this->days[$low];
        if ($segment['day'] !== $day) {
            return $segment['first_position'];
        }

        return $segment['first_position'] + $this->timeline->countUntil(
            $day,
            $segment['planned_count'],
            $timestamp,
            $this->timezone,
            $this->cutoff,
            $this->seed,
            Segment::TYPE_CUSTOMER
        );
    }

    /**
     * Draws from the shared random source, which the caller seeds for the order.
     *
     * @return int|null customer position, or null for a guest
     */
    public function select(int $registered, float $guestRatio): ?int
    {
        if ($registered === 0 || $this->random->chance($guestRatio)) {
            return null;
        }

        $wantLoyal = $this->random->chance(self::LOYAL_ORDER_SHARE);
        $position = 0;
        for ($try = 0; $try < self::MAX_TRIES; $try++) {
            $position = $this->random->int(0, $registered - 1);
            if ($this->isLoyal($position) === $wantLoyal) {
                break;
            }
        }

        return $position;
    }

    private function isLoyal(int $position): bool
    {
        return Random::seedFor($this->seed, 'loyal', $position) % self::LOYAL_CUSTOMER_EVERY === 0;
    }
}
