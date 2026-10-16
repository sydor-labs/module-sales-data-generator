<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Perspective\SalesDataGenerator\Model\OrderStatusResolver;

/**
 * Order statuses the generator can produce, labelled with their state.
 */
class OrderStatuses implements OptionSourceInterface
{
    public function __construct(
        private readonly OrderStatusResolver $statusResolver
    ) {
    }

    /**
     * @inheritDoc
     * @return list<array{value: string, label: string}>
     */
    #[\Override]
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->statusResolver->getStatuses() as $code => $status) {
            $options[] = ['value' => $code, 'label' => sprintf('%s (%s)', $status['label'], $status['state'])];
        }

        return $options;
    }
}
