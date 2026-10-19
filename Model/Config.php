<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Serialize\Serializer\Json;
use Perspective\SalesDataGenerator\Block\Adminhtml\System\Config\WeightedOptions;

/**
 * Generator settings from Stores > Configuration > Perspective Studio > Sales Data Generator.
 */
class Config
{
    private const string XML_PATH_PAYMENT_METHODS = 'sales_data_generator/orders/payment_methods';
    private const string XML_PATH_SHIPPING_METHODS = 'sales_data_generator/orders/shipping_methods';
    private const string XML_PATH_ORDER_STATUSES = 'sales_data_generator/orders/order_statuses';
    private const string XML_PATH_BATCH_SIZE = 'sales_data_generator/generation/batch_size';
    private const int DEFAULT_BATCH_SIZE = 100;
    private const string XML_PATH_WORKERS = 'sales_data_generator/generation/workers';
    private const string XML_PATH_CATALOG_SEGMENT_SIZE = 'sales_data_generator/generation/catalog_segment_size';
    private const int DEFAULT_CATALOG_SEGMENT_SIZE = 500;

    public function __construct(
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Json $serializer
    ) {
    }

    /**
     * @return array<string, float> payment method code => weight
     * @throws LocalizedException
     */
    public function getPaymentMethodWeights(): array
    {
        return $this->getWeights(self::XML_PATH_PAYMENT_METHODS);
    }

    /**
     * @return array<string, float> shipping method ("carrier_method") => weight
     * @throws LocalizedException
     */
    public function getShippingMethodWeights(): array
    {
        return $this->getWeights(self::XML_PATH_SHIPPING_METHODS);
    }

    /**
     * @return array<string, float> order status code => weight
     * @throws LocalizedException
     */
    public function getOrderStatusWeights(): array
    {
        return $this->getWeights(self::XML_PATH_ORDER_STATUSES);
    }

    /**
     * Customers or orders processed per queue message.
     */
    public function getBatchSize(): int
    {
        $size = (int)$this->scopeConfig->getValue(self::XML_PATH_BATCH_SIZE);

        return $size > 0 ? $size : self::DEFAULT_BATCH_SIZE;
    }

    /**
     * Message chains started per job.
     */
    public function getWorkers(): int
    {
        return max(1, (int)$this->scopeConfig->getValue(self::XML_PATH_WORKERS));
    }

    /**
     * Categories or products per segment.
     */
    public function getCatalogSegmentSize(): int
    {
        $size = (int)$this->scopeConfig->getValue(self::XML_PATH_CATALOG_SEGMENT_SIZE);

        return $size > 0 ? $size : self::DEFAULT_CATALOG_SEGMENT_SIZE;
    }

    /**
     * Rows with a positive weight.
     *
     * @return array<string, float>
     * @throws LocalizedException when no row has a positive weight
     */
    private function getWeights(string $path): array
    {
        $value = (string)$this->scopeConfig->getValue($path);
        $rows = $value === '' ? [] : $this->serializer->unserialize($value);

        $weights = [];
        foreach (is_array($rows) ? $rows : [] as $row) {
            $code = (string)($row[WeightedOptions::COLUMN_CODE] ?? '');
            $weight = (float)($row[WeightedOptions::COLUMN_WEIGHT] ?? 0);
            if ($code !== '' && $weight > 0) {
                $weights[$code] = $weight;
            }
        }
        if ($weights === []) {
            throw new LocalizedException(
                __('Configure "%1" in Stores > Configuration > Perspective Studio > Sales Data Generator.', $path)
            );
        }

        return $weights;
    }
}
