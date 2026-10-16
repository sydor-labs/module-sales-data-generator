<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Plugin;

use Magento\CatalogInventory\Model\Configuration;
use Perspective\SalesDataGenerator\Model\GenerationContext;

/**
 * Turns stock management off while data is generated, so large runs don't stop once the catalog sells out.
 */
class DisableManageStockPlugin
{
    public function __construct(
        private readonly GenerationContext $generationContext
    ) {
    }

    /**
     * @param Configuration $subject
     * @param bool|int|string $result
     * @return bool|int|string
     */
    public function afterGetManageStock(Configuration $subject, mixed $result): mixed
    {
        return $this->generationContext->isActive() ? 0 : $result;
    }
}
