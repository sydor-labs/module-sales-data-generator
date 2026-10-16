<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Random\Engine\Xoshiro256StarStar;
use Random\Randomizer;

/**
 * Seedable random source shared by all generators, so a run can be reproduced with --seed.
 */
class Random
{
    /**
     * @var Randomizer
     */
    private Randomizer $randomizer;

    public function __construct()
    {
        $this->randomizer = new Randomizer();
    }

    /**
     * Seed of an independent random sequence for one purpose of a run, e.g. ("order", 587).
     *
     * @param int $seed seed of the run
     * @param string|int ...$parts what the sequence is for
     * @return int
     */
    public static function seedFor(int $seed, string|int ...$parts): int
    {
        return (int)hexdec(substr(hash('xxh3', $seed . ':' . implode(':', $parts)), 0, 15));
    }

    /**
     * Seed the generator; the same seed and store data give the same customers and orders.
     */
    public function seed(int $seed): void
    {
        $this->randomizer = new Randomizer(new Xoshiro256StarStar($seed));
    }

    /**
     * Random integer within [min, max].
     */
    public function int(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * Random float within [0, 1).
     */
    public function float(): float
    {
        return $this->randomizer->nextFloat();
    }

    /**
     * Random float within [min, max).
     */
    public function between(float $min, float $max): float
    {
        return $min + ($max - $min) * $this->float();
    }

    /**
     * True with the given probability.
     */
    public function chance(float $probability): bool
    {
        return $this->float() < $probability;
    }

    /**
     * Random element of a non-empty list.
     *
     * @template T
     * @param list<T> $items
     * @return T
     */
    public function pick(array $items): mixed
    {
        return $items[$this->randomizer->getInt(0, count($items) - 1)];
    }

    /**
     * Same elements in random order.
     *
     * @template T
     * @param list<T> $items
     * @return list<T>
     */
    public function shuffle(array $items): array
    {
        return $this->randomizer->shuffleArray($items);
    }

    /**
     * Key chosen with probability proportional to its weight.
     *
     * @param array<int|string, float|int> $weights
     * @return int|string
     */
    public function weighted(array $weights): int|string
    {
        $target = $this->float() * array_sum($weights);
        foreach ($weights as $key => $weight) {
            $target -= $weight;
            if ($target < 0) {
                return $key;
            }
        }

        return array_key_last($weights);
    }

    /**
     * Index chosen from a prefix of a cumulative weight list, using binary search.
     *
     * @param list<float> $cumulative ascending running totals of the weights
     * @param int $length number of leading entries to choose from
     * @return int
     */
    public function weightedPrefix(array $cumulative, int $length): int
    {
        $target = $this->float() * $cumulative[$length - 1];
        $low = 0;
        $high = $length - 1;
        while ($low < $high) {
            $middle = intdiv($low + $high, 2);
            if ($cumulative[$middle] > $target) {
                $high = $middle;
            } else {
                $low = $middle + 1;
            }
        }

        return $low;
    }
}
