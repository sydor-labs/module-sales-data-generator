<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Block\Adminhtml\System\Config;

use Magento\Framework\View\Element\Html\Select;

/**
 * Dropdown cell of a dynamic rows config field.
 */
class OptionSelect extends Select
{
    /**
     * Called by the dynamic rows field with the cell input name.
     */
    public function setInputName(string $value): self
    {
        return $this->setName($value);
    }

    /**
     * Called by the dynamic rows field with the cell input ID; it lets saved rows preselect their value.
     */
    public function setInputId(string $value): self
    {
        return $this->setId($value);
    }
}
