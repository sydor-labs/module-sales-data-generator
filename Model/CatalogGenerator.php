<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Catalog\Api\CategoryRepositoryInterface;
use Magento\Catalog\Api\Data\CategoryInterface;
use Magento\Catalog\Api\Data\CategoryInterfaceFactory;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductInterfaceFactory;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Type;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Filter\TranslitUrl;

/**
 * Creates plausible categories and simple products, tagged so a job can find them again.
 */
class CatalogGenerator
{
    public const int STOCK_QTY = 1000;

    private const string XML_PATH_DEFAULT_TAX_CLASS = 'tax/classes/default_product_tax_class';

    private const array DEPARTMENTS = [
        'Home', 'Kitchen', 'Outdoor', 'Sports', 'Electronics', 'Office', 'Kids', 'Beauty', 'Garden', 'Travel',
        'Pets', 'Fitness', 'Fashion', 'Books', 'Music', 'Health', 'Tools', 'Automotive', 'Toys', 'Crafts',
    ];

    private const array SECTIONS = [
        'Accessories', 'Essentials', 'Gear', 'Furniture', 'Decor', 'Storage', 'Lighting', 'Apparel', 'Footwear',
        'Bags', 'Gadgets', 'Care', 'Supplies', 'Equipment', 'Textiles', 'Gifts', 'Basics', 'New Arrivals',
    ];

    private const array ADJECTIVES = [
        'Classic', 'Urban', 'Eco', 'Premium', 'Sport', 'Vintage', 'Smart', 'Cozy', 'Light', 'Pro', 'Compact',
        'Deluxe', 'Everyday', 'Travel', 'Nordic', 'Soft', 'Bold', 'Essential', 'Modern', 'Rustic',
    ];

    private const array MATERIALS = [
        'Cotton', 'Leather', 'Steel', 'Bamboo', 'Wool', 'Ceramic', 'Glass', 'Linen', 'Canvas', 'Oak', 'Silicone',
        'Aluminium', 'Denim', 'Copper',
    ];

    private const array NOUNS = [
        'Backpack', 'Mug', 'Jacket', 'Lamp', 'Bottle', 'Watch', 'Sneakers', 'Notebook', 'Chair', 'Headphones',
        'Blanket', 'Scarf', 'Tote', 'Planter', 'Speaker', 'Wallet', 'Kettle', 'Towel', 'Cap', 'Organizer',
        'Candle', 'Pillow', 'Gloves', 'Umbrella', 'Tumbler', 'Shelf', 'Mat', 'Pouch',
    ];

    public function __construct(
        private readonly CategoryInterfaceFactory $categoryFactory,
        private readonly CategoryRepositoryInterface $categoryRepository,
        private readonly ProductInterfaceFactory $productFactory,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly TranslitUrl $translitUrl,
        private readonly ScopeConfigInterface $scopeConfig,
        private readonly Random $random,
        private readonly RandomFactory $randomFactory
    ) {
    }

    /**
     * Number of top-level categories among the given total.
     */
    public static function departmentCount(int $categoriesTotal): int
    {
        return max(1, (int)ceil(sqrt($categoriesTotal)));
    }

    /**
     * Tag the URL key of a job's category or product ends with.
     *
     * @param string $token job token
     * @param int $position
     * @return string
     */
    public static function tag(string $token, int $position): string
    {
        return $token . '-' . $position;
    }

    /**
     * SKU of the product at a position; unique, so it identifies the product.
     */
    public static function sku(string $token, int $position): string
    {
        return 'sdg-' . $token . '-' . $position;
    }

    /**
     * Create the category at a position; draws from the shared random source, which the caller seeds.
     *
     * @param string $token
     * @param int $seed job seed; departments take distinct names from a list shuffled with it
     * @param int $position
     * @param int $parentId root category for departments, a department for the others
     * @param bool $isDepartment
     * @return CategoryInterface
     * @throws LocalizedException
     */
    public function createCategory(
        string $token,
        int $seed,
        int $position,
        int $parentId,
        bool $isDepartment
    ): CategoryInterface {
        $name = $isDepartment
            ? $this->departmentName($seed, $position)
            : $this->random->pick(self::ADJECTIVES) . ' ' . $this->random->pick(self::SECTIONS);

        $category = $this->categoryFactory->create()
            ->setParentId($parentId)
            ->setName($name)
            ->setIsActive(true)
            ->setIncludeInMenu($isDepartment);
        $category->setCustomAttribute('url_key', $this->urlKey($name, $token, $position));

        return $this->categoryRepository->save($category);
    }

    /**
     * Create the simple product at a position; draws from the shared random source, which the caller seeds.
     *
     * @param string $token
     * @param int $position
     * @param int $websiteId
     * @param list<int> $categoryIds categories to choose from; empty leaves the product without one
     * @return ProductInterface
     * @throws LocalizedException
     */
    public function createProduct(string $token, int $position, int $websiteId, array $categoryIds): ProductInterface
    {
        $name = sprintf(
            '%s %s %s',
            $this->random->pick(self::ADJECTIVES),
            $this->random->pick(self::MATERIALS),
            $this->random->pick(self::NOUNS)
        );
        // Log-uniform between 5 and 500: cheap products are more common than expensive ones.
        $price = round(exp(log(5) + $this->random->float() * (log(500) - log(5)))) - 0.01;

        /** @var Product $product */
        $product = $this->productFactory->create();
        $product->setSku(self::sku($token, $position))
            ->setName($name)
            ->setTypeId(Type::TYPE_SIMPLE)
            ->setAttributeSetId($product->getDefaultAttributeSetId())
            ->setPrice(max(0.99, $price))
            ->setWeight(round($this->random->between(0.1, 5), 2))
            ->setVisibility(Visibility::VISIBILITY_BOTH)
            ->setStatus(Status::STATUS_ENABLED)
            ->setWebsiteIds([$websiteId])
            ->setUrlKey($this->urlKey($name, $token, $position))
            ->setStockData(['qty' => self::STOCK_QTY, 'is_in_stock' => 1, 'use_config_manage_stock' => 1])
            ->setQuantityAndStockStatus(['qty' => self::STOCK_QTY, 'is_in_stock' => 1]);
        $product->setCustomAttribute(
            'tax_class_id',
            (int)$this->scopeConfig->getValue(self::XML_PATH_DEFAULT_TAX_CLASS)
        );
        if ($categoryIds !== []) {
            $assigned = [$this->random->pick($categoryIds)];
            if (count($categoryIds) > 1 && $this->random->chance(0.3)) {
                $assigned[] = $this->random->pick($categoryIds);
            }
            $product->setCategoryIds(array_values(array_unique($assigned)));
        }

        return $this->productRepository->save($product);
    }

    /**
     * Distinct names for the first departments; a numbered name once the list runs out.
     */
    private function departmentName(int $seed, int $position): string
    {
        $random = $this->randomFactory->create();
        $random->seed(Random::seedFor($seed, 'departments'));
        $names = $random->shuffle(self::DEPARTMENTS);
        $round = intdiv($position, count($names));

        return $names[$position % count($names)] . ($round > 0 ? ' ' . ($round + 1) : '');
    }

    private function urlKey(string $name, string $token, int $position): string
    {
        return $this->translitUrl->filter($name) . '-' . self::tag($token, $position);
    }
}
