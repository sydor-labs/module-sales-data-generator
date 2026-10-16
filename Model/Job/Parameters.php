<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

/**
 * Validated generation parameters, as given in the admin form or on the command line.
 */
readonly class Parameters
{
    public const string CUSTOMERS = 'customers';
    public const string ORDERS = 'orders';
    public const string FROM = 'from';
    public const string TO = 'to';
    public const string STORE = 'store';
    public const string GUEST_RATIO = 'guest_ratio';
    public const string SEED = 'seed';
    public const string SKIP_STATISTICS = 'skip_statistics';
    public const string CUTOFF = 'cutoff';
    public const string TOKEN = 'token';
    public const string CATEGORIES = 'categories';
    public const string PRODUCTS = 'products';

    /**
     * @param int $customers
     * @param int $orders
     * @param string $from first day, Y-m-d in store timezone
     * @param string $to last day, Y-m-d in store timezone
     * @param int $storeId
     * @param float $guestRatio share of guest orders, 0..1
     * @param int|null $seed set for every planned job, even when none was given
     * @param bool $skipStatistics
     * @param int|null $cutoff UTC timestamp of planning; no event is generated after it
     * @param string|null $token unique tag of everything the job creates, safe across reinstalls
     * @param int $categories categories to create before customers and orders
     * @param int $products simple products to create before customers and orders
     */
    public function __construct(
        public int $customers,
        public int $orders,
        public string $from,
        public string $to,
        public int $storeId,
        public float $guestRatio,
        public ?int $seed,
        public bool $skipStatistics,
        public ?int $cutoff = null,
        public ?string $token = null,
        public int $categories = 0,
        public int $products = 0
    ) {
    }

    /**
     * Copy with the values a job is planned with.
     */
    public function withRuntime(int $seed, int $cutoff, string $token): self
    {
        return new self(
            $this->customers,
            $this->orders,
            $this->from,
            $this->to,
            $this->storeId,
            $this->guestRatio,
            $seed,
            $this->skipStatistics,
            $cutoff,
            $token,
            $this->categories,
            $this->products
        );
    }

    /**
     * @return array{customers: int, orders: int, from: string, to: string, store: int, guest_ratio: float,
     *     seed: int|null, skip_statistics: bool, cutoff: int|null, token: string|null, categories: int,
     *     products: int}
     */
    public function toArray(): array
    {
        return [
            self::CUSTOMERS => $this->customers,
            self::ORDERS => $this->orders,
            self::FROM => $this->from,
            self::TO => $this->to,
            self::STORE => $this->storeId,
            self::GUEST_RATIO => $this->guestRatio,
            self::SEED => $this->seed,
            self::SKIP_STATISTICS => $this->skipStatistics,
            self::CUTOFF => $this->cutoff,
            self::TOKEN => $this->token,
            self::CATEGORIES => $this->categories,
            self::PRODUCTS => $this->products,
        ];
    }

    /**
     * Restore parameters saved with a job.
     *
     * @param array<string, mixed> $data
     * @return self
     */
    public static function fromArray(array $data): self
    {
        return new self(
            (int)$data[self::CUSTOMERS],
            (int)$data[self::ORDERS],
            (string)$data[self::FROM],
            (string)$data[self::TO],
            (int)$data[self::STORE],
            (float)$data[self::GUEST_RATIO],
            isset($data[self::SEED]) ? (int)$data[self::SEED] : null,
            (bool)$data[self::SKIP_STATISTICS],
            isset($data[self::CUTOFF]) ? (int)$data[self::CUTOFF] : null,
            isset($data[self::TOKEN]) ? (string)$data[self::TOKEN] : null,
            (int)($data[self::CATEGORIES] ?? 0),
            (int)($data[self::PRODUCTS] ?? 0)
        );
    }
}
