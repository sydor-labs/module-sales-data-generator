<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Model\Category;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\EntityManager\MetadataPool;
use Perspective\SalesDataGenerator\Model\CustomerGenerator;

/**
 * Finds what a job already created, so customers can be looked up by position and an interrupted batch
 * resumes without duplicates.
 */
class GeneratedEntities
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool
    ) {
    }

    public function findProductIdBySku(string $sku): ?int
    {
        $connection = $this->resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_entity'), ['entity_id'])
                ->where('sku = ?', $sku)
        );

        return $id === false ? null : (int)$id;
    }

    /**
     * Categories a job created, found by the tag their URL key ends with.
     *
     * @param string $token job token
     * @return array<int, int> category ID by position
     */
    public function getJobCategoryIds(string $token): array
    {
        $connection = $this->resource->getConnection();
        $linkField = $this->metadataPool->getMetadata(CategoryInterface::class)->getLinkField();
        $rows = $connection->fetchPairs(
            $connection->select()
                ->from(['value' => $this->resource->getTableName('catalog_category_entity_varchar')], ['value'])
                ->join(
                    ['category' => $this->resource->getTableName('catalog_category_entity')],
                    'category.' . $linkField . ' = value.' . $linkField,
                    ['entity_id']
                )
                ->where('value.attribute_id = ?', $this->getCategoryAttributeId('url_key'))
                ->where('value.store_id = 0')
                ->where('value.value LIKE ?', '%-' . $token . '-%')
        );

        $ids = [];
        foreach ($rows as $urlKey => $categoryId) {
            $ids[(int)substr((string)$urlKey, strrpos((string)$urlKey, '-') + 1)] = (int)$categoryId;
        }
        ksort($ids);

        return $ids;
    }

    /**
     * Customer IDs by email, through the unique (email, website_id) index.
     *
     * @param list<string> $emails
     * @param int $websiteId
     * @return array<string, int>
     */
    public function findCustomerIds(array $emails, int $websiteId): array
    {
        if ($emails === []) {
            return [];
        }
        $connection = $this->resource->getConnection();
        $pairs = $connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['email', 'entity_id'])
                ->where('website_id = ?', $websiteId)
                ->where('email IN (?)', $emails)
        );

        return array_map('intval', array_change_key_case($pairs, CASE_LOWER));
    }

    /**
     * Fallback when the email of a position cannot be rebuilt, e.g. after the name lists changed; slow.
     */
    public function findCustomerIdByTag(string $tag, int $websiteId): ?int
    {
        $connection = $this->resource->getConnection();
        $id = $connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('customer_entity'), ['entity_id'])
                ->where('website_id = ?', $websiteId)
                ->where('email LIKE ?', '%.' . $tag . '@' . CustomerGenerator::EMAIL_DOMAIN)
                ->limit(1)
        );

        return $id === false ? null : (int)$id;
    }

    public function getMaxOrderId(): int
    {
        $connection = $this->resource->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()->from($this->resource->getTableName('sales_order'), ['MAX(entity_id)'])
        );
    }

    /**
     * Positions of a job's orders created after the given order ID.
     *
     * @param string $prefix order tag prefix of the job, e.g. "sdg-2f37b986-"
     * @param int $afterEntityId
     * @return list<int>
     */
    public function findOrderPositions(string $prefix, int $afterEntityId): array
    {
        $connection = $this->resource->getConnection();
        $tags = $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName('sales_order'), ['ext_order_id'])
                ->where('entity_id > ?', $afterEntityId)
                ->where('ext_order_id LIKE ?', $prefix . '%')
        );

        return array_map(static fn ($tag): int => (int)substr((string)$tag, strlen($prefix)), $tags);
    }

    private function getCategoryAttributeId(string $code): int
    {
        return (int)$this->eavConfig->getAttribute(Category::ENTITY, $code)->getId();
    }
}
