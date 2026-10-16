<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Shipping\Model\Config\Source\Allmethods;

/**
 * Methods of active carriers, grouped by carrier.
 */
class ShippingMethods implements OptionSourceInterface
{
    public function __construct(
        private readonly Allmethods $allMethods
    ) {
    }

    /**
     * @inheritDoc
     * @return list<array<string, mixed>>
     */
    #[\Override]
    public function toOptionArray(): array
    {
        return array_values(array_filter(
            $this->allMethods->toOptionArray(true),
            static fn (array $option): bool => $option['value'] !== ''
        ));
    }
}
