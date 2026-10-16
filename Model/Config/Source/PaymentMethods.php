<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use Magento\Payment\Api\PaymentMethodListInterface;
use Magento\Payment\Helper\Data as PaymentHelper;
use Magento\Payment\Model\Method\Free;
use Magento\Store\Model\Store;

/**
 * Active payment methods that can be placed without a payment gateway.
 */
class PaymentMethods implements OptionSourceInterface
{
    public function __construct(
        private readonly PaymentMethodListInterface $paymentMethodList,
        private readonly PaymentHelper $paymentHelper
    ) {
    }

    /**
     * @inheritDoc
     * @return list<array{value: string, label: string}>
     */
    #[\Override]
    public function toOptionArray(): array
    {
        $options = [];
        foreach ($this->paymentMethodList->getActiveList(Store::DEFAULT_STORE_ID) as $method) {
            $code = $method->getCode();
            // Zero Subtotal Checkout applies only to orders with nothing to pay.
            if ($code === Free::PAYMENT_METHOD_FREE_CODE || !$this->isOffline($code)) {
                continue;
            }
            $options[] = ['value' => $code, 'label' => sprintf('%s (%s)', $method->getTitle(), $code)];
        }

        return $options;
    }

    /**
     * A method whose model cannot be created, e.g. of a disabled module, counts as online and is not offered.
     */
    private function isOffline(string $code): bool
    {
        try {
            return (bool)$this->paymentHelper->getMethodInstance($code)->isOffline();
        } catch (\Throwable) {
            return false;
        }
    }
}
