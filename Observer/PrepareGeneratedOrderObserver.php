<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Observer;

use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Quote\Model\Quote;
use Magento\Sales\Model\Order;
use Perspective\SalesDataGenerator\Model\OrderGenerator;

/**
 * Tags generated orders, so an interrupted job can find them, and keeps them from sending
 * "new order" emails to fake addresses.
 */
class PrepareGeneratedOrderObserver implements ObserverInterface
{
    /**
     * @inheritDoc
     */
    #[\Override]
    public function execute(Observer $observer): void
    {
        /** @var Quote $quote */
        $quote = $observer->getEvent()->getData('quote');
        if (!$quote->getData(OrderGenerator::QUOTE_FLAG)) {
            return;
        }

        /** @var Order $order */
        $order = $observer->getEvent()->getData('order');
        $order->setCanSendNewEmailFlag(false);
        if ($quote->getData(OrderGenerator::QUOTE_ORDER_TAG)) {
            $order->setExtOrderId((string)$quote->getData(OrderGenerator::QUOTE_ORDER_TAG));
        }
    }
}
