<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use DateInterval;
use DateTimeImmutable;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Quote\Api\CartManagementInterface;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\QuoteFactory;
use Magento\Quote\Model\QuoteManagement;
use Magento\Sales\Api\InvoiceOrderInterface;
use Magento\Sales\Api\OrderManagementInterface;
use Magento\Sales\Api\OrderRepositoryInterface;
use Magento\Sales\Api\RefundOrderInterface;
use Magento\Sales\Api\ShipOrderInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Api\Data\StoreInterface;
use Perspective\SalesDataGenerator\Model\ResourceModel\Backdater;

/**
 * Places one order through the regular quote checkout with configured payment and shipping methods,
 * takes it to a configured status and backdates it.
 *
 * @phpstan-import-type Person from PersonGenerator
 * @phpstan-import-type OrderDates from Backdater
 */
class OrderGenerator
{
    /**
     * Quote data key marking generated quotes.
     */
    public const string QUOTE_FLAG = 'perspective_sales_data_generator';
    public const string QUOTE_ORDER_TAG = 'perspective_sales_data_generator_order_tag';
    public const string GUEST_EMAIL_DOMAIN = 'example.org';

    private const string PAYMENT_METHOD_PURCHASE_ORDER = 'purchaseorder';
    private const array LINE_COUNT_WEIGHTS = [1 => 50, 2 => 28, 3 => 14, 4 => 5, 5 => 3];
    private const array QTY_WEIGHTS = [1 => 85, 2 => 11, 3 => 4];

    /**
     * @var array<string, float>
     */
    private array $paymentWeights = [];

    /**
     * @var array<string, float>
     */
    private array $shippingWeights = [];

    /**
     * @var array<string, float>
     */
    private array $statusWeights = [];

    public function __construct(
        private readonly QuoteFactory $quoteFactory,
        private readonly CartRepositoryInterface $cartRepository,
        private readonly QuoteManagement $quoteManagement,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly InvoiceOrderInterface $invoiceOrder,
        private readonly ShipOrderInterface $shipOrder,
        private readonly RefundOrderInterface $refundOrder,
        private readonly OrderManagementInterface $orderManagement,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly Config $config,
        private readonly OrderStatusResolver $statusResolver,
        private readonly ProductPool $productPool,
        private readonly PersonGenerator $personGenerator,
        private readonly RegionResolver $regionResolver,
        private readonly Random $random,
        private readonly Backdater $backdater
    ) {
    }

    /**
     * Read the configured payment methods, shipping methods and statuses.
     *
     * @throws LocalizedException when a setting is empty or a status can't be produced
     */
    public function loadSettings(): void
    {
        $this->paymentWeights = $this->config->getPaymentMethodWeights();
        $this->shippingWeights = $this->config->getShippingMethodWeights();
        $this->statusWeights = $this->config->getOrderStatusWeights();
        foreach (array_keys($this->statusWeights) as $status) {
            if ($this->statusResolver->getState($status) === null) {
                throw new LocalizedException(__(
                    'Order status "%1" is not assigned to any of these states: %2.',
                    $status,
                    implode(', ', OrderStatusResolver::STATES)
                ));
            }
        }
    }

    /**
     * Prefix of the ext_order_id tags of a job's orders.
     */
    public static function getOrderTagPrefix(string $token): string
    {
        return 'sdg-' . $token . '-';
    }

    /**
     * Place an order at the given time for a customer, or for a new guest when no customer is given.
     *
     * @param StoreInterface $store
     * @param DateTimeImmutable $placedAt UTC time
     * @param CustomerInterface|null $customer
     * @param string $guestTag unique tag the guest email ends with
     * @param string $orderTag saved as ext_order_id, so the order can be found again
     * @return GeneratedOrder
     * @throws LocalizedException
     */
    public function create(
        StoreInterface $store,
        DateTimeImmutable $placedAt,
        ?CustomerInterface $customer,
        string $guestTag,
        string $orderTag
    ): GeneratedOrder {
        $quote = $this->createQuote($store, $customer, $guestTag);
        $quote->setData(self::QUOTE_ORDER_TAG, $orderTag);
        $paymentMethod = (string)$quote->getPayment()->getMethod();
        $shippingMethod = (string)$quote->getShippingAddress()->getShippingMethod();
        /** @var Order $order */
        $order = $this->quoteManagement->submit($quote);
        $orderId = (int)$order->getEntityId();

        $status = (string)$this->random->weighted($this->statusWeights);
        $targetState = (string)$this->statusResolver->getState($status);
        $dates = ['placed' => $placedAt];
        try {
            $reachedState = $this->runLifecycle($orderId, $placedAt, $targetState, $dates);
            $status = $this->applyStatus($orderId, $reachedState === $targetState ? $status : null);
        } finally {
            // Also when a later step fails, so the placed order does not stay dated today.
            $this->backdater->backdateOrder($orderId, (int)$quote->getId(), $dates);
        }

        return new GeneratedOrder($orderId, $status, $paymentMethod, $shippingMethod);
    }

    /**
     * @throws LocalizedException
     */
    private function createQuote(StoreInterface $store, ?CustomerInterface $customer, string $guestTag): Quote
    {
        $storeId = (int)$store->getId();
        $quote = $this->quoteFactory->create();
        $quote->setStoreId($storeId);
        $quote->setData(self::QUOTE_FLAG, true);

        if ($customer !== null) {
            $quote->assignCustomer($customer);
        } else {
            $guest = $this->personGenerator->create(self::GUEST_EMAIL_DOMAIN, $guestTag);
            $quote->setCustomerIsGuest(true)
                ->setCustomerGroupId(GroupInterface::NOT_LOGGED_IN_ID)
                ->setCustomerEmail($guest['email'])
                ->setCustomerFirstname($guest['firstname'])
                ->setCustomerLastname($guest['lastname'])
                ->setCheckoutMethod(CartManagementInterface::METHOD_GUEST);
            $this->fillAddresses($quote, $guest);
        }

        $this->addItems($quote, $storeId);
        // The payment method instance is resolved through the quote, which needs an ID for that.
        $this->cartRepository->save($quote);

        $this->selectShippingMethod($quote);
        $quote->setData('inventory_processed', false);
        // Payment availability checks use the order total.
        $quote->collectTotals();
        $this->selectPaymentMethod($quote);
        $this->cartRepository->save($quote);

        return $quote;
    }

    /**
     * @param Quote $quote
     * @param Person $person
     */
    private function fillAddresses(Quote $quote, array $person): void
    {
        $data = $person['address'];
        $region = $this->regionResolver->resolve($data['country_id'], $data['region_code']);
        $addressData = [
            'firstname' => $person['firstname'],
            'lastname' => $person['lastname'],
            'email' => $person['email'],
            'country_id' => $data['country_id'],
            'region_id' => $region['region_id'],
            'region' => $region['region'],
            'city' => $data['city'],
            'postcode' => $data['postcode'],
            'street' => [$data['street']],
            'telephone' => $data['telephone'],
        ];
        $quote->getBillingAddress()->addData($addressData);
        $quote->getShippingAddress()->addData($addressData);
    }

    /**
     * Add 1-5 different lines.
     *
     * @throws LocalizedException when no product could be added
     */
    private function addItems(Quote $quote, int $storeId): void
    {
        $lineCount = (int)$this->random->weighted(self::LINE_COUNT_WEIGHTS);
        $added = [];
        $lastError = null;
        for ($attempt = 0; count($added) < $lineCount && $attempt < $lineCount * 4; $attempt++) {
            $purchasable = $this->productPool->pick();
            $key = $purchasable['product_id'] . ':' . json_encode($purchasable['super_attribute']);
            if (isset($added[$key])) {
                continue;
            }
            try {
                /** @var Product $product */
                $product = $this->productRepository->getById($purchasable['product_id'], false, $storeId, true);
            } catch (NoSuchEntityException $e) {
                // Deleted from the catalog after the pool was loaded; another product takes its place.
                $lastError = $e->getMessage();
                continue;
            }
            $request = ['qty' => (int)$this->random->weighted(self::QTY_WEIGHTS)];
            if ($purchasable['super_attribute'] !== []) {
                $request['super_attribute'] = $purchasable['super_attribute'];
            }
            try {
                $result = $quote->addProduct($product, new DataObject($request));
            } catch (LocalizedException $e) {
                $result = $e->getMessage();
            }
            if (is_string($result) || $result->getData('has_error')) {
                $lastError = is_string($result) ? $result : (string)$result->getMessage();
                if (!is_string($result)) {
                    $quote->deleteItem($result);
                }
                continue;
            }
            $added[$key] = true;
        }

        if ($added === []) {
            throw new LocalizedException(__('No product could be added to the cart: %1', (string)$lastError));
        }
    }

    /**
     * Pick a configured shipping method by weight among those that have a rate for the address.
     *
     * @throws LocalizedException when none has
     */
    private function selectShippingMethod(Quote $quote): void
    {
        $address = $quote->getShippingAddress();
        $address->setCollectShippingRates(true)->collectShippingRates();

        $candidates = $this->shippingWeights;
        while ($candidates !== []) {
            $code = (string)$this->random->weighted($candidates);
            if ($address->getShippingRateByCode($code)) {
                $address->setShippingMethod($code);
                return;
            }
            unset($candidates[$code]);
        }

        throw new LocalizedException(__(
            'None of the configured shipping methods has a rate for country %1.',
            (string)$address->getCountryId()
        ));
    }

    /**
     * Pick a configured payment method by weight among those available for the order.
     *
     * @throws LocalizedException when none is
     */
    private function selectPaymentMethod(Quote $quote): void
    {
        $candidates = $this->paymentWeights;
        while ($candidates !== []) {
            $code = (string)$this->random->weighted($candidates);
            $data = ['method' => $code];
            if ($code === self::PAYMENT_METHOD_PURCHASE_ORDER) {
                $data['po_number'] = 'PO-' . $this->random->int(100000, 999999);
            }
            try {
                $quote->getPayment()->importData($data);
                return;
            } catch (LocalizedException) {
                unset($candidates[$code]);
            }
        }

        throw new LocalizedException(__(
            'None of the configured payment methods is available for country %1.',
            (string)$quote->getBillingAddress()->getCountryId()
        ));
    }

    /**
     * Take the order towards the target state.
     *
     * @param int $orderId
     * @param DateTimeImmutable $placedAt
     * @param string $targetState
     * @param OrderDates $dates receives the time of every step taken
     * @return string the state reached
     */
    private function runLifecycle(int $orderId, DateTimeImmutable $placedAt, string $targetState, array &$dates): string
    {
        $now = new DateTimeImmutable('now', $placedAt->getTimezone());

        if ($targetState === Order::STATE_NEW) {
            return Order::STATE_NEW;
        }
        if ($targetState === Order::STATE_HOLDED || $targetState === Order::STATE_CANCELED) {
            $actedAt = $this->after($placedAt, 1800, 3 * 86400);
            if ($actedAt > $now) {
                return Order::STATE_NEW;
            }
            if ($targetState === Order::STATE_HOLDED) {
                $this->orderManagement->hold($orderId);
                $dates['hold'] = $actedAt;
            } else {
                $this->orderManagement->cancel($orderId);
                $dates['canceled'] = $actedAt;
            }
            return $targetState;
        }

        $invoicedAt = $this->after($placedAt, 300, 2 * 86400);
        if ($invoicedAt > $now) {
            return Order::STATE_NEW;
        }
        $this->invoiceOrder->execute($orderId);
        $dates['invoice'] = $invoicedAt;
        if ($targetState === Order::STATE_PROCESSING) {
            return Order::STATE_PROCESSING;
        }

        $shippedAt = $this->after($invoicedAt, 3600, 4 * 86400);
        if ($shippedAt > $now) {
            return Order::STATE_PROCESSING;
        }
        $this->shipOrder->execute($orderId);
        $dates['shipment'] = $shippedAt;
        if ($targetState === Order::STATE_COMPLETE) {
            return Order::STATE_COMPLETE;
        }

        $refundedAt = $this->after($shippedAt, 2 * 86400, 20 * 86400);
        if ($refundedAt > $now) {
            return Order::STATE_COMPLETE;
        }
        $this->refundOrder->execute($orderId);
        $dates['creditmemo'] = $refundedAt;

        return Order::STATE_CLOSED;
    }

    /**
     * Set the chosen status when it differs from the one the state change gave the order.
     *
     * @param int $orderId
     * @param string|null $status null keeps the current status
     * @return string the order status
     */
    private function applyStatus(int $orderId, ?string $status): string
    {
        /** @var Order $order */
        $order = $this->orderRepository->get($orderId);
        if ($status === null || $order->getStatus() === $status) {
            return (string)$order->getStatus();
        }
        $order->setStatus($status);
        $this->orderRepository->save($order);

        return $status;
    }

    private function after(DateTimeImmutable $time, int $minSeconds, int $maxSeconds): DateTimeImmutable
    {
        return $time->add(new DateInterval('PT' . $this->random->int($minSeconds, $maxSeconds) . 'S'));
    }
}
