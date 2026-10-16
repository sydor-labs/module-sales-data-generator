<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use DateTimeZone;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * The store generated data belongs to.
 */
class StoreContext
{
    public function __construct(
        private readonly StoreManagerInterface $storeManager,
        private readonly TimezoneInterface $timezone
    ) {
    }

    /**
     * Make the store current, so prices, taxes and config are taken from it.
     *
     * @throws NoSuchEntityException
     */
    public function activate(int $storeId): StoreInterface
    {
        $store = $this->storeManager->getStore($storeId);
        $this->storeManager->setCurrentStore((int)$store->getId());

        return $store;
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getRootCategoryId(int $storeId): int
    {
        $store = $this->storeManager->getStore($storeId);

        return (int)$this->storeManager->getGroup($store->getStoreGroupId())->getRootCategoryId();
    }

    /**
     * Make the admin store current, so catalog entities are saved with default values for all store views.
     */
    public function activateAdmin(): void
    {
        $this->storeManager->setCurrentStore(Store::DEFAULT_STORE_ID);
    }

    /**
     * @throws NoSuchEntityException
     */
    public function getTimezone(int $storeId): DateTimeZone
    {
        return new DateTimeZone($this->timezone->getConfigTimezone(
            ScopeInterface::SCOPE_STORE,
            $this->storeManager->getStore($storeId)->getCode()
        ));
    }
}
