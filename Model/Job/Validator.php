<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Job;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Perspective\SalesDataGenerator\Model\Config;
use Perspective\SalesDataGenerator\Model\Config\Source\PaymentMethods;
use Perspective\SalesDataGenerator\Model\Config\Source\ShippingMethods;
use Perspective\SalesDataGenerator\Model\OrderStatusResolver;
use Perspective\SalesDataGenerator\Model\ResourceModel\PurchasableProducts;

/**
 * Refuses a job that is bound to fail, before anything is created.
 */
class Validator
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly PurchasableProducts $purchasableProducts,
        private readonly Config $config,
        private readonly PaymentMethods $paymentMethods,
        private readonly ShippingMethods $shippingMethods,
        private readonly OrderStatusResolver $statusResolver
    ) {
    }

    /**
     * @throws LocalizedException listing every problem found
     */
    public function validate(Parameters $parameters): void
    {
        $errors = [];
        if ($parameters->categories + $parameters->products + $parameters->customers + $parameters->orders === 0) {
            $errors[] = __('Nothing to generate: set the number of categories, products, customers or orders.');
        }

        $store = $this->storeManager->getStore($parameters->storeId);
        if (!$store->isActive()) {
            $errors[] = __('Store view "%1" is disabled.', $store->getCode());
        }

        if ($parameters->orders > 0) {
            if ($parameters->customers === 0 && $parameters->guestRatio < 1) {
                $errors[] = __(
                    'Orders need customers unless every order is a guest one: set customers or a guest share of 1.'
                );
            }
            if ($parameters->products === 0
                && !$this->purchasableProducts->hasAny((int)$store->getId(), (int)$store->getWebsiteId())
            ) {
                $errors[] = __(
                    'Store view "%1" has no products that can be ordered: set products to generate or add enabled, '
                    . 'visible products to its website.',
                    $store->getCode()
                );
            }
            array_push($errors, ...$this->validateOrderSettings());
        }

        if ($errors !== []) {
            throw new LocalizedException(__(implode(' ', array_map('strval', $errors))));
        }
    }

    /**
     * Configured payment and shipping methods that are still active, and statuses the generator can produce.
     *
     * @return list<\Magento\Framework\Phrase|string>
     */
    private function validateOrderSettings(): array
    {
        $errors = [];
        $checks = [
            'payment' => [fn (): array => $this->config->getPaymentMethodWeights(), $this->paymentMethods],
            'shipping' => [fn (): array => $this->config->getShippingMethodWeights(), $this->shippingMethods],
        ];
        foreach ($checks as $kind => [$getWeights, $source]) {
            try {
                $configured = array_keys($getWeights());
            } catch (LocalizedException $e) {
                $errors[] = $e->getMessage();
                continue;
            }
            if (array_intersect($configured, $this->getValues($source)) === []) {
                $errors[] = __(
                    'None of the configured %1 methods (%2) is active.',
                    $kind,
                    implode(', ', $configured)
                );
            }
        }

        try {
            $unknown = array_filter(
                array_keys($this->config->getOrderStatusWeights()),
                fn (string $status): bool => $this->statusResolver->getState($status) === null
            );
            if ($unknown !== []) {
                $errors[] = __(
                    'Order statuses %1 are not assigned to any of these states: %2.',
                    implode(', ', $unknown),
                    implode(', ', OrderStatusResolver::STATES)
                );
            }
        } catch (LocalizedException $e) {
            $errors[] = $e->getMessage();
        }

        return $errors;
    }

    /**
     * Option values, optgroups flattened.
     *
     * @return list<string>
     */
    private function getValues(OptionSourceInterface $source): array
    {
        $values = [];
        $walk = static function (array $options) use (&$walk, &$values): void {
            foreach ($options as $option) {
                if (is_array($option['value'])) {
                    $walk($option['value']);
                } else {
                    $values[] = (string)$option['value'];
                }
            }
        };
        $walk($source->toOptionArray());

        return $values;
    }
}
