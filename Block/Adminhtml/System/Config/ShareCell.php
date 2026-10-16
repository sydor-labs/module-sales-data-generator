<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Block\Adminhtml\System\Config;

use Magento\Framework\View\Element\AbstractBlock;

/**
 * Read-only cell that shows the share of a row; filled in by the weighted-rows script.
 */
class ShareCell extends AbstractBlock
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _toHtml(): string
    {
        return '<span class="sales-data-generator-share" data-role="share"></span>';
    }
}
