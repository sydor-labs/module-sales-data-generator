<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\ResourceModel;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\ConfigurableProduct\Model\Product\Type\Configurable;
use Magento\Eav\Model\Config as EavConfig;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\EntityManager\MetadataPool;

/**
 * Products a store can sell.
 */
class PurchasableProducts
{
    public function __construct(
        private readonly ResourceConnection $resource,
        private readonly EavConfig $eavConfig,
        private readonly MetadataPool $metadataPool,
        private readonly Visibility $visibility
    ) {
    }

    /**
     * Whether the store can sell at least one product, without reading the whole catalog.
     */
    public function hasAny(int $storeId, int $websiteId): bool
    {
        $select = $this->sellableProducts(Type::TYPE_SIMPLE, $storeId, $websiteId)
            ->where('e.required_options = 0')
            ->limit(1);

        return $this->getConnection()->fetchOne($select) !== false
            || $this->getConfigurableVariations($storeId, $websiteId) !== [];
    }

    /**
     * Enabled, visible simple products without required custom options.
     *
     * @return list<int> product IDs, ascending
     */
    public function getSimpleProductIds(int $storeId, int $websiteId): array
    {
        $select = $this->sellableProducts(Type::TYPE_SIMPLE, $storeId, $websiteId)
            ->where('e.required_options = 0');

        return array_map('intval', $this->getConnection()->fetchCol($select));
    }

    /**
     * Enabled, visible configurable products with the super attribute selections of their enabled children.
     *
     * @return array<int, list<array<int, int>>> attribute ID => option ID selections by product ID,
     *     children ordered by ID; products without a usable child are left out
     */
    public function getConfigurableVariations(int $storeId, int $websiteId): array
    {
        $connection = $this->getConnection();
        $linkField = $this->getLinkField();
        $parents = $connection->fetchPairs(
            $this->sellableProducts(Configurable::TYPE_CODE, $storeId, $websiteId)
                ->reset(Select::COLUMNS)
                ->columns(['link_id' => 'e.' . $linkField, 'entity_id' => 'e.entity_id'])
        );
        if ($parents === []) {
            return [];
        }

        $superAttributes = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from($this->resource->getTableName('catalog_product_super_attribute'), ['product_id', 'attribute_id'])
                ->where('product_id IN (?)', array_keys($parents))
                ->order(['product_id', 'position', 'attribute_id'])
        ) as $row) {
            $superAttributes[(int)$row['product_id']][] = (int)$row['attribute_id'];
        }

        $children = $connection->select()
            ->from(['link' => $this->resource->getTableName('catalog_product_super_link')], ['parent_id'])
            ->join(
                ['child' => $this->resource->getTableName('catalog_product_entity')],
                'child.entity_id = link.product_id',
                ['child_id' => 'child.entity_id', 'child_link_id' => 'child.' . $linkField]
            )
            ->where('link.parent_id IN (?)', array_keys($parents))
            ->order(['link.parent_id', 'child.entity_id']);
        $this->filterByAttribute($children, 'child', 'status', $storeId, [Status::STATUS_ENABLED]);
        $childRows = $connection->fetchAll($children);

        $values = $this->getIntValues(
            array_column($childRows, 'child_link_id'),
            array_unique(array_merge([], ...array_values($superAttributes))),
            $storeId
        );

        $variations = [];
        foreach ($childRows as $row) {
            $parentLinkId = (int)$row['parent_id'];
            $selection = [];
            foreach ($superAttributes[$parentLinkId] ?? [] as $attributeId) {
                $value = $values[(int)$row['child_link_id']][$attributeId] ?? null;
                if ($value === null) {
                    continue 2;
                }
                $selection[$attributeId] = $value;
            }
            if ($selection !== []) {
                $variations[(int)$parents[$parentLinkId]][] = $selection;
            }
        }

        return $variations;
    }

    /**
     * Products of a type assigned to the website, enabled and visible in the catalog or search.
     */
    private function sellableProducts(string $typeId, int $storeId, int $websiteId): Select
    {
        $select = $this->getConnection()->select()
            ->from(['e' => $this->resource->getTableName('catalog_product_entity')], ['entity_id'])
            ->join(
                ['website' => $this->resource->getTableName('catalog_product_website')],
                'website.product_id = e.entity_id',
                []
            )
            ->where('website.website_id = ?', $websiteId)
            ->where('e.type_id = ?', $typeId)
            ->order('e.entity_id');
        $this->filterByAttribute($select, 'e', 'status', $storeId, [Status::STATUS_ENABLED]);
        $this->filterByAttribute($select, 'e', 'visibility', $storeId, $this->visibility->getVisibleInSiteIds());

        return $select;
    }

    /**
     * Keep only rows whose int attribute, store view value or default, is one of the given values.
     *
     * @param Select $select
     * @param string $alias alias of the product entity table in the select
     * @param string $attributeCode
     * @param int $storeId
     * @param list<int> $allowed
     */
    private function filterByAttribute(
        Select $select,
        string $alias,
        string $attributeCode,
        int $storeId,
        array $allowed
    ): void {
        $connection = $this->getConnection();
        $linkField = $this->getLinkField();
        $attributeId = (int)$this->eavConfig->getAttribute(Product::ENTITY, $attributeCode)->getId();
        $table = $this->resource->getTableName('catalog_product_entity_int');
        $default = $alias . '_' . $attributeCode . '_default';
        $store = $alias . '_' . $attributeCode . '_store';

        $select->join(
            [$default => $table],
            sprintf('%1$s.%2$s = %3$s.%2$s', $default, $linkField, $alias)
            . $connection->quoteInto(" AND {$default}.attribute_id = ?", $attributeId)
            . " AND {$default}.store_id = 0",
            []
        )->joinLeft(
            [$store => $table],
            sprintf('%1$s.%2$s = %3$s.%2$s', $store, $linkField, $alias)
            . $connection->quoteInto(" AND {$store}.attribute_id = ?", $attributeId)
            . $connection->quoteInto(" AND {$store}.store_id = ?", $storeId),
            []
        )->where("COALESCE({$store}.value, {$default}.value) IN (?)", $allowed);
    }

    /**
     * Int attribute values of products, store view value or default.
     *
     * @param list<int|string> $linkIds
     * @param list<int> $attributeIds
     * @param int $storeId
     * @return array<int, array<int, int>> value by attribute ID by product link ID
     */
    private function getIntValues(array $linkIds, array $attributeIds, int $storeId): array
    {
        if ($linkIds === [] || $attributeIds === []) {
            return [];
        }
        $linkField = $this->getLinkField();
        $rows = $this->getConnection()->fetchAll(
            $this->getConnection()->select()
                ->from(
                    $this->resource->getTableName('catalog_product_entity_int'),
                    ['link_id' => $linkField, 'attribute_id', 'store_id', 'value']
                )
                ->where($linkField . ' IN (?)', $linkIds)
                ->where('attribute_id IN (?)', $attributeIds)
                ->where('store_id IN (?)', [0, $storeId])
                ->where('value IS NOT NULL')
                ->order('store_id')
        );

        $values = [];
        foreach ($rows as $row) {
            // Ordered by store, so the store view value overwrites the default one.
            $values[(int)$row['link_id']][(int)$row['attribute_id']] = (int)$row['value'];
        }

        return $values;
    }

    private function getLinkField(): string
    {
        return $this->metadataPool->getMetadata(ProductInterface::class)->getLinkField();
    }

    private function getConnection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }
}
