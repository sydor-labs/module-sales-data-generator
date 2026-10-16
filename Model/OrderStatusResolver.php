<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Sales\Model\Order;
use Magento\Sales\Model\ResourceModel\Order\Status\CollectionFactory;

/**
 * Order statuses the generator can produce, with the state each one belongs to.
 */
class OrderStatusResolver
{
    /**
     * States the generator can bring an order to.
     */
    public const array STATES = [
        Order::STATE_NEW,
        Order::STATE_HOLDED,
        Order::STATE_PROCESSING,
        Order::STATE_COMPLETE,
        Order::STATE_CLOSED,
        Order::STATE_CANCELED,
    ];

    /**
     * @var array<string, array{label: string, state: string}>|null
     */
    private ?array $statuses = null;

    public function __construct(
        private readonly CollectionFactory $collectionFactory
    ) {
    }

    /**
     * Statuses assigned to a supported state, keyed by status code.
     *
     * A status assigned to several states belongs to the one where it is the default status, if any.
     *
     * @return array<string, array{label: string, state: string}>
     */
    public function getStatuses(): array
    {
        if ($this->statuses === null) {
            $collection = $this->collectionFactory->create()->joinStates();
            $collection->getSelect()
                ->where('state_table.state IN (?)', self::STATES)
                ->order(['state_table.is_default DESC', 'main_table.label ASC']);

            $this->statuses = [];
            foreach ($collection->getData() as $row) {
                $this->statuses[$row['status']] ??= [
                    'label' => (string)$row['label'],
                    'state' => (string)$row['state'],
                ];
            }
        }

        return $this->statuses;
    }

    public function getState(string $status): ?string
    {
        return $this->getStatuses()[$status]['state'] ?? null;
    }
}
