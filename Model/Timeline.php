<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use DateInterval;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Spreads events over a period the way a real store sees them, without ever holding all of them in memory.
 */
class Timeline
{
    /**
     * Monday first, as in date('N').
     */
    private const array WEEKDAY_WEIGHTS = [1 => 1.0, 1.0, 1.05, 1.05, 0.95, 0.8, 0.85];

    /**
     * Daily traffic curve, indexed by hour in the store timezone.
     */
    private const array HOUR_WEIGHTS = [
        0.3, 0.15, 0.1, 0.05, 0.05, 0.1, 0.3, 0.6, 0.9, 1.1, 1.3, 1.5,
        1.5, 1.4, 1.3, 1.2, 1.2, 1.3, 1.5, 1.8, 1.9, 1.7, 1.2, 0.6,
    ];

    private const float DAY_NOISE = 0.25;
    private const float HOUR_NOISE = 0.15;
    private const float MINUTE_NOISE = 0.3;
    private const int CACHE_LIMIT = 2000;

    /**
     * @var array<string, mixed>
     */
    private array $cache = [];

    public function __construct(
        private readonly RandomFactory $randomFactory
    ) {
    }

    /**
     * Split events over the days of a period.
     *
     * @param string $firstDay Y-m-d in store timezone
     * @param string $lastDay Y-m-d in store timezone
     * @param int $count
     * @param DateTimeZone $timezone store timezone
     * @param int $cutoff UTC timestamp no event may be later than
     * @param int $seed
     * @param string $stream what the events are, e.g. "order"; keeps streams independent
     * @return array<string, int> event count by day (Y-m-d), days without events left out
     */
    public function allocateDays(
        string $firstDay,
        string $lastDay,
        int $count,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): array {
        $first = new DateTimeImmutable($firstDay, $timezone);
        $last = new DateTimeImmutable($lastDay, $timezone);
        $cutoffDay = (new DateTimeImmutable('@' . $cutoff))->setTimezone($timezone)->setTime(0, 0);
        if ($last > $cutoffDay) {
            $last = $cutoffDay;
        }
        if ($count <= 0 || $first > $last) {
            return [];
        }

        $random = $this->createRandom($seed, $stream, 'days');
        $totalDays = max(1, (int)$first->diff($last)->days);
        $weights = [];
        $index = 0;
        for ($day = $first; $day <= $last; $day = $day->add(new DateInterval('P1D'))) {
            $key = $day->format('Y-m-d');
            $noise = $random->between(1 - self::DAY_NOISE, 1 + self::DAY_NOISE);
            $hours = $this->hourWeights($key, $timezone, $cutoff, $seed, $stream);
            $elapsed = array_sum($hours['available']) / array_sum($hours['full']);
            $weights[$key] = $this->seasonalWeight($day, $index / $totalDays) * $noise * $elapsed;
            $index++;
        }

        return array_filter($this->allocate($weights, $count, $random->float()));
    }

    /**
     * Sorted moments of a day, from the given position within the day.
     *
     * @param string $day Y-m-d in store timezone
     * @param int $dayTotal events of the whole day
     * @param int $from position within the day of the first moment returned
     * @param int $count
     * @param DateTimeZone $timezone
     * @param int $cutoff
     * @param int $seed
     * @param string $stream
     * @return list<int> UTC timestamps
     */
    public function moments(
        string $day,
        int $dayTotal,
        int $from,
        int $count,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): array {
        $moments = [];
        $to = min($from + $count, $dayTotal);
        $hourStart = 0;
        foreach ($this->hourCounts($day, $dayTotal, $timezone, $cutoff, $seed, $stream) as $hour => $hourCount) {
            $hourEnd = $hourStart + $hourCount;
            if ($hourEnd > $from && $hourStart < $to) {
                $minuteStart = $hourStart;
                $minutes = $this->minuteCounts($day, $hour, $hourCount, $timezone, $cutoff, $seed, $stream);
                foreach ($minutes as $minute => $minuteCount) {
                    $minuteEnd = $minuteStart + $minuteCount;
                    if ($minuteEnd > $from && $minuteStart < $to) {
                        $seconds = $this->minuteMoments(
                            $day,
                            $hour,
                            $minute,
                            $minuteCount,
                            $timezone,
                            $cutoff,
                            $seed,
                            $stream
                        );
                        $sliceFrom = max($from, $minuteStart) - $minuteStart;
                        $sliceTo = min($to, $minuteEnd) - $minuteStart;
                        array_push($moments, ...array_slice($seconds, $sliceFrom, $sliceTo - $sliceFrom));
                    }
                    $minuteStart = $minuteEnd;
                }
            }
            $hourStart = $hourEnd;
            if ($hourStart >= $to) {
                break;
            }
        }

        return $moments;
    }

    /**
     * How many moments of a day are not later than the given time.
     *
     * @param string $day Y-m-d in store timezone
     * @param int $dayTotal
     * @param int $timestamp UTC
     * @param DateTimeZone $timezone
     * @param int $cutoff
     * @param int $seed
     * @param string $stream
     * @return int
     */
    public function countUntil(
        string $day,
        int $dayTotal,
        int $timestamp,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): int {
        $counted = 0;
        foreach ($this->hourCounts($day, $dayTotal, $timezone, $cutoff, $seed, $stream) as $hour => $hourCount) {
            $hourStart = $this->localTime($day, $hour, 0, $timezone);
            if ($hourStart > $timestamp) {
                break;
            }
            if ($hourStart + 3600 <= $timestamp) {
                $counted += $hourCount;
                continue;
            }
            $minutes = $this->minuteCounts($day, $hour, $hourCount, $timezone, $cutoff, $seed, $stream);
            foreach ($minutes as $minute => $minuteCount) {
                $minuteStart = $hourStart + $minute * 60;
                if ($minuteStart > $timestamp) {
                    break;
                }
                if ($minuteStart + 60 <= $timestamp) {
                    $counted += $minuteCount;
                    continue;
                }
                $seconds = $this->minuteMoments(
                    $day,
                    $hour,
                    $minute,
                    $minuteCount,
                    $timezone,
                    $cutoff,
                    $seed,
                    $stream
                );
                foreach ($seconds as $moment) {
                    if ($moment > $timestamp) {
                        break;
                    }
                    $counted++;
                }
            }
            break;
        }

        return $counted;
    }

    /**
     * @return list<int> event count by hour
     */
    private function hourCounts(
        string $day,
        int $dayTotal,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): array {
        return $this->cached(
            ['h', $seed, $cutoff, $stream, $day, $dayTotal],
            function () use ($day, $dayTotal, $timezone, $cutoff, $seed, $stream): array {
                $weights = $this->hourWeights($day, $timezone, $cutoff, $seed, $stream)['available'];
                $offset = $this->createRandom($seed, $stream, $day, 'hours')->float();

                return $this->allocate($weights, $dayTotal, $offset);
            }
        );
    }

    /**
     * @return list<int> event count by minute of the hour
     */
    private function minuteCounts(
        string $day,
        int $hour,
        int $hourTotal,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): array {
        return $this->cached(
            ['m', $seed, $cutoff, $stream, $day, $hour, $hourTotal],
            function () use ($day, $hour, $hourTotal, $timezone, $cutoff, $seed, $stream): array {
                $random = $this->createRandom($seed, $stream, $day, $hour, 'minutes');
                $hourStart = $this->localTime($day, $hour, 0, $timezone);
                $weights = [];
                for ($minute = 0; $minute < 60; $minute++) {
                    $noise = $random->between(1 - self::MINUTE_NOISE, 1 + self::MINUTE_NOISE);
                    $weights[$minute] = $noise * $this->availableShare($hourStart + $minute * 60, 60, $cutoff);
                }

                return $this->allocate($weights, $hourTotal, $random->float());
            }
        );
    }

    /**
     * Sorted moments of one minute.
     *
     * @return list<int> UTC timestamps
     */
    private function minuteMoments(
        string $day,
        int $hour,
        int $minute,
        int $count,
        DateTimeZone $timezone,
        int $cutoff,
        int $seed,
        string $stream
    ): array {
        return $this->cached(
            ['s', $seed, $cutoff, $stream, $day, $hour, $minute, $count],
            function () use ($day, $hour, $minute, $count, $timezone, $cutoff, $seed, $stream): array {
                $random = $this->createRandom($seed, $stream, $day, $hour, $minute, 'seconds');
                $minuteStart = $this->localTime($day, $hour, $minute, $timezone);
                $lastSecond = min(59, $cutoff - $minuteStart);
                $moments = [];
                for ($i = 0; $i < $count; $i++) {
                    $moments[] = $minuteStart + $random->int(0, max(0, $lastSecond));
                }
                sort($moments);

                return $moments;
            }
        );
    }

    /**
     * Hour weights with their noise, in full and limited to the time before the cutoff.
     *
     * @return array{full: list<float>, available: list<float>}
     */
    private function hourWeights(string $day, DateTimeZone $timezone, int $cutoff, int $seed, string $stream): array
    {
        return $this->cached(
            ['w', $seed, $cutoff, $stream, $day],
            function () use ($day, $timezone, $cutoff, $seed, $stream): array {
                $random = $this->createRandom($seed, $stream, $day, 'hour-noise');
                $full = [];
                $available = [];
                foreach (self::HOUR_WEIGHTS as $hour => $weight) {
                    $full[$hour] = $weight * $random->between(1 - self::HOUR_NOISE, 1 + self::HOUR_NOISE);
                    $start = $this->localTime($day, $hour, 0, $timezone);
                    $available[$hour] = $full[$hour] * $this->availableShare($start, 3600, $cutoff);
                }

                return ['full' => $full, 'available' => $available];
            }
        );
    }

    /**
     * Relative order volume of a day: weekday × season × growth over the period.
     *
     * @param DateTimeImmutable $day
     * @param float $progress position of the day within the period, 0..1
     * @return float
     */
    private function seasonalWeight(DateTimeImmutable $day, float $progress): float
    {
        $month = (int)$day->format('n');
        $dayOfMonth = (int)$day->format('j');

        $season = match (true) {
            $month === 11 && $dayOfMonth >= 24 => 2.4,
            $month === 12 && $dayOfMonth <= 22 => 1.6,
            $month === 12 => 0.9,
            $month === 1 => 0.75,
            $month === 2 => 0.85,
            $month >= 6 && $month <= 8 => 0.9,
            $month === 9 => 1.1,
            default => 1.0,
        };
        $growth = 0.8 + 0.4 * $progress;

        return self::WEEKDAY_WEIGHTS[(int)$day->format('N')] * $season * $growth;
    }

    /**
     * Systematic sampling: counts proportional to the weights that add up to exactly the total.
     *
     * @param array<int|string, float> $weights non-negative, at least one positive when total > 0
     * @param int $total
     * @param float $offset random value in [0, 1)
     * @return array<int|string, int>
     */
    private function allocate(array $weights, int $total, float $offset): array
    {
        $sum = array_sum($weights);
        $counts = [];
        $cumulative = 0.0;
        $previous = 0;
        foreach ($weights as $key => $weight) {
            $cumulative += $weight;
            $boundary = $sum > 0 ? (int)floor($cumulative / $sum * $total + $offset) : 0;
            $counts[$key] = $boundary - $previous;
            $previous = $boundary;
        }
        // Floating point may leave the last boundary one short of the total.
        if ($sum > 0 && $previous !== $total) {
            $lastKey = array_key_last(array_filter($weights));
            $counts[$lastKey] += $total - $previous;
        }

        return $counts;
    }

    /**
     * Share of an interval that lies before the cutoff, 0..1.
     */
    private function availableShare(int $start, int $length, int $cutoff): float
    {
        return max(0.0, min(1.0, ($cutoff - $start) / $length));
    }

    /**
     * UTC timestamp of a local time.
     */
    private function localTime(string $day, int $hour, int $minute, DateTimeZone $timezone): int
    {
        return (int)$this->cached(
            ['t', $timezone->getName(), $day, $hour, $minute],
            static fn (): int => (new DateTimeImmutable(sprintf('%s %02d:%02d:00', $day, $hour, $minute), $timezone))
                ->getTimestamp()
        );
    }

    /**
     * @param list<string|int> $key
     * @param callable(): mixed $compute
     * @return mixed
     */
    private function cached(array $key, callable $compute): mixed
    {
        $cacheKey = implode('|', $key);
        if (!array_key_exists($cacheKey, $this->cache)) {
            if (count($this->cache) >= self::CACHE_LIMIT) {
                $this->cache = [];
            }
            $this->cache[$cacheKey] = $compute();
        }

        return $this->cache[$cacheKey];
    }

    private function createRandom(int $seed, string|int ...$parts): Random
    {
        $random = $this->randomFactory->create();
        $random->seed(Random::seedFor($seed, ...$parts));

        return $random;
    }
}
