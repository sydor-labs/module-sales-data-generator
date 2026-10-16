<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use DateTimeImmutable;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Validates raw parameter values from the admin form or the command line.
 */
class ParametersParser
{
    public const float DEFAULT_GUEST_RATIO = 0.25;

    public function __construct(
        private readonly StoreManagerInterface $storeManager
    ) {
    }

    /**
     * Missing or empty values get their defaults; an empty count means none of that entity.
     *
     * @param array<string, mixed> $input values keyed by the Parameters constants; "store" is an ID or a code
     * @return Parameters
     * @throws LocalizedException
     */
    public function parse(array $input): Parameters
    {
        $value = static fn (string $key): mixed => ($input[$key] ?? '') === '' ? null : $input[$key];

        $from = $this->parseDay($value(Parameters::FROM), Parameters::FROM)
            ?? (new DateTimeImmutable('-1 year'))->format('Y-m-d');
        $to = $this->parseDay($value(Parameters::TO), Parameters::TO)
            ?? (new DateTimeImmutable('today'))->format('Y-m-d');
        if ($from > $to) {
            throw new LocalizedException(__('The first day must not be later than the last day.'));
        }
        $seed = $value(Parameters::SEED);

        return new Parameters(
            $this->parseCount($value(Parameters::CUSTOMERS) ?? 0, Parameters::CUSTOMERS),
            $this->parseCount($value(Parameters::ORDERS) ?? 0, Parameters::ORDERS),
            $from,
            $to,
            $this->resolveStoreId($value(Parameters::STORE)),
            $this->parseRatio($value(Parameters::GUEST_RATIO) ?? self::DEFAULT_GUEST_RATIO),
            $seed === null ? null : $this->parseCount($seed, Parameters::SEED),
            (bool)$value(Parameters::SKIP_STATISTICS),
            null,
            null,
            $this->parseCount($value(Parameters::CATEGORIES) ?? 0, Parameters::CATEGORIES),
            $this->parseCount($value(Parameters::PRODUCTS) ?? 0, Parameters::PRODUCTS)
        );
    }

    /**
     * @throws LocalizedException
     */
    private function parseCount(mixed $value, string $name): int
    {
        if (!is_numeric($value) || (string)(int)$value !== (string)$value || (int)$value < 0) {
            throw new LocalizedException(__('"%1" must be a non-negative integer.', $name));
        }

        return (int)$value;
    }

    /**
     * @throws LocalizedException
     */
    private function parseRatio(mixed $value): float
    {
        if (!is_numeric($value) || (float)$value < 0 || (float)$value > 1) {
            throw new LocalizedException(__('"%1" must be between 0 and 1.', Parameters::GUEST_RATIO));
        }

        return (float)$value;
    }

    /**
     * @throws LocalizedException
     */
    private function parseDay(mixed $value, string $name): ?string
    {
        if ($value === null) {
            return null;
        }
        $day = DateTimeImmutable::createFromFormat('!Y-m-d', (string)$value);
        if ($day === false || $day->format('Y-m-d') !== $value) {
            throw new LocalizedException(__('"%1" must be a day in YYYY-MM-DD format.', $name));
        }

        return (string)$value;
    }

    /**
     * @throws LocalizedException
     */
    private function resolveStoreId(mixed $value): int
    {
        try {
            $store = $value === null
                ? $this->storeManager->getDefaultStoreView()
                : $this->storeManager->getStore($value);
        } catch (NoSuchEntityException) {
            $store = null;
        }
        if ($store === null || (int)$store->getId() === 0) {
            throw new LocalizedException(__('Store view "%1" was not found.', (string)$value));
        }

        return (int)$store->getId();
    }
}
