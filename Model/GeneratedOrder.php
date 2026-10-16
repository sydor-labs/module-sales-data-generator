<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

/**
 * What a generated order ended up with.
 */
readonly class GeneratedOrder
{
    public function __construct(
        public int $orderId,
        public string $status,
        public string $paymentMethod,
        public string $shippingMethod
    ) {
    }
}
