<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\Exception\LocalizedException;
use Magento\Store\Model\StoreManagerInterface;
use Perspective\SalesDataGenerator\Model\ResourceModel\PurchasableProducts;

/**
 * Products that can be bought in a store, with a long-tail popularity so reports have clear bestsellers.
 *
 * @phpstan-type Purchasable array{product_id: int, super_attribute: array<int, int>}
 * @phpstan-type PoolItem array{product_id: int, variations: list<array<int, int>>}
 */
class ProductPool
{
    /**
     * @var list<PoolItem>
     */
    private array $items = [];

    /**
     * @var list<float>
     */
    private array $cumulative = [];

    /**
     * @var string|null
     */
    private ?string $loadedKey = null;

    public function __construct(
        private readonly PurchasableProducts $purchasableProducts,
        private readonly StoreManagerInterface $storeManager,
        private readonly Random $random,
        private readonly RandomFactory $randomFactory
    ) {
    }

    /**
     * Collect enabled, visible simple products and configurable variations of the store.
     *
     * @param int $storeId
     * @param int $seed job seed, orders the products by popularity
     * @param string $token job token; the cached pool is reused for the batches of one job only, since the
     *     catalog may change between jobs even when they share a seed
     * @throws LocalizedException when the store has nothing to sell
     */
    public function load(int $storeId, int $seed, string $token): void
    {
        $key = $storeId . ':' . $seed . ':' . $token;
        if ($this->loadedKey === $key) {
            return;
        }

        $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
        // Required custom options would need values the generator cannot guess.
        $items = [];
        foreach ($this->purchasableProducts->getSimpleProductIds($storeId, $websiteId) as $productId) {
            $items[$productId] = ['product_id' => $productId, 'variations' => [[]]];
        }
        $configurables = $this->purchasableProducts->getConfigurableVariations($storeId, $websiteId);
        foreach ($configurables as $productId => $variations) {
            $items[$productId] = ['product_id' => $productId, 'variations' => $variations];
        }
        // The shuffle below only repeats for the same input order.
        ksort($items);
        $items = array_values($items);
        if ($items === []) {
            throw new LocalizedException(__('Store %1 has no products that can be ordered.', $storeId));
        }

        $ranking = $this->randomFactory->create();
        $ranking->seed(Random::seedFor($seed, 'products'));
        $this->items = $ranking->shuffle($items);
        $this->cumulative = [];
        $total = 0.0;
        foreach (array_keys($this->items) as $rank) {
            $total += 1 / (($rank + 1) ** 0.8);
            $this->cumulative[] = $total;
        }
        $this->loadedKey = $key;
    }

    /**
     * Pick a product (and a random variation of a configurable one); popular products come up far more often.
     *
     * @return Purchasable
     */
    public function pick(): array
    {
        $item = $this->items[$this->random->weightedPrefix($this->cumulative, count($this->items))];

        return ['product_id' => $item['product_id'], 'super_attribute' => $this->random->pick($item['variations'])];
    }

    public function count(): int
    {
        return count($this->items);
    }
}
