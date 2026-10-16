<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use DateTimeImmutable;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Moves freshly created customers and orders back in time.
 *
 * @phpstan-type OrderDates array{placed: DateTimeImmutable, hold?: DateTimeImmutable,
 *     canceled?: DateTimeImmutable, invoice?: DateTimeImmutable, shipment?: DateTimeImmutable,
 *     creditmemo?: DateTimeImmutable}
 */
class Backdater
{
    private const string FORMAT = 'Y-m-d H:i:s';

    /**
     * Sales document tables by entity name: [entity table, grid table].
     */
    private const array DOCUMENT_TABLES = [
        'invoice' => ['sales_invoice', 'sales_invoice_grid'],
        'shipment' => ['sales_shipment', 'sales_shipment_grid'],
        'creditmemo' => ['sales_creditmemo', 'sales_creditmemo_grid'],
    ];

    public function __construct(
        private readonly ResourceConnection $resource
    ) {
    }

    /**
     * @param int $customerId
     * @param DateTimeImmutable $createdAt UTC time
     */
    public function backdateCustomer(int $customerId, DateTimeImmutable $createdAt): void
    {
        $connection = $this->getConnection();
        $date = $createdAt->format(self::FORMAT);

        $connection->update(
            $this->resource->getTableName('customer_entity'),
            ['created_at' => $date, 'updated_at' => $date],
            ['entity_id = ?' => $customerId]
        );
        $connection->update(
            $this->resource->getTableName('customer_address_entity'),
            ['created_at' => $date, 'updated_at' => $date],
            ['parent_id = ?' => $customerId]
        );
    }

    /**
     * Rewrite the dates of an order, its quote, items, status history and documents.
     *
     * @param int $orderId
     * @param int $quoteId
     * @param OrderDates $dates UTC times
     */
    public function backdateOrder(int $orderId, int $quoteId, array $dates): void
    {
        $connection = $this->getConnection();
        $placed = $dates['placed']->format(self::FORMAT);
        $cartCreated = $dates['placed']->modify('-' . (5 + $orderId % 40) . ' minutes')->format(self::FORMAT);
        $updated = max($dates)->format(self::FORMAT);

        $connection->beginTransaction();
        try {
            $orderDates = ['created_at' => $placed, 'updated_at' => $updated];
            $connection->update(
                $this->resource->getTableName('sales_order'),
                $orderDates,
                ['entity_id = ?' => $orderId]
            );
            $connection->update(
                $this->resource->getTableName('sales_order_grid'),
                $orderDates,
                ['entity_id = ?' => $orderId]
            );
            $connection->update(
                $this->resource->getTableName('sales_order_item'),
                $orderDates,
                ['order_id = ?' => $orderId]
            );
            $connection->update(
                $this->resource->getTableName('quote'),
                [
                    'created_at' => $cartCreated,
                    'updated_at' => $placed,
                    'converted_at' => $placed,
                ],
                ['entity_id = ?' => $quoteId]
            );

            $historyTable = $this->resource->getTableName('sales_order_status_history');
            $connection->update($historyTable, ['created_at' => $placed], ['parent_id = ?' => $orderId]);
            foreach (array_keys(self::DOCUMENT_TABLES) as $entityName) {
                if (isset($dates[$entityName])) {
                    $connection->update(
                        $historyTable,
                        ['created_at' => $dates[$entityName]->format(self::FORMAT)],
                        ['parent_id = ?' => $orderId, 'entity_name = ?' => $entityName]
                    );
                }
            }
            if (isset($dates['canceled'])) {
                $connection->update(
                    $historyTable,
                    ['created_at' => $dates['canceled']->format(self::FORMAT)],
                    ['parent_id = ?' => $orderId, 'status = ?' => 'canceled']
                );
            }

            foreach (self::DOCUMENT_TABLES as $entityName => [$table, $gridTable]) {
                if (!isset($dates[$entityName])) {
                    continue;
                }
                $documentDate = $dates[$entityName]->format(self::FORMAT);
                $documentDates = ['created_at' => $documentDate, 'updated_at' => $documentDate];
                $connection->update(
                    $this->resource->getTableName($table),
                    $documentDates,
                    ['order_id = ?' => $orderId]
                );
                $connection->update(
                    $this->resource->getTableName($gridTable),
                    $documentDates + ['order_created_at' => $placed],
                    ['order_id = ?' => $orderId]
                );
            }
            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollBack();
            throw $e;
        }
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
