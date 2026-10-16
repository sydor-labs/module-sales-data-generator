<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;

/**
 * Link to the page where a generation job is configured and started.
 */
class GenerateButton extends Field
{
    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _getElementHtml(AbstractElement $element): string
    {
        return sprintf(
            '<a class="action-default" href="%s">%s</a>',
            $this->escapeUrl($this->getUrl('sales_data_generator/generate/index')),
            $this->escapeHtml(__('Generate Sales Data...'))
        );
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function render(AbstractElement $element): string
    {
        // The scope label and the "Use Default" checkbox mean nothing for a link.
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue()->unsCanRestoreToDefault();

        return parent::render($element);
    }
}
