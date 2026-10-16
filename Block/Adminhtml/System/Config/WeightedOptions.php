<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field\FieldArray\AbstractFieldArray;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;

/**
 * Dynamic rows of option + weight, with the resulting share of each row shown while editing.
 */
class WeightedOptions extends AbstractFieldArray
{
    public const string COLUMN_CODE = 'code';
    public const string COLUMN_WEIGHT = 'weight';
    private const string COLUMN_SHARE = 'share';

    /**
     * @var OptionSelect|null
     */
    private ?OptionSelect $select = null;

    /**
     * @param Context $context
     * @param OptionSourceInterface $optionSource
     * @param string $optionLabel header of the option column
     * @param array<string, mixed> $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly OptionSourceInterface $optionSource,
        private readonly string $optionLabel = 'Option',
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    #[\Override]
    protected function _prepareToRender(): void
    {
        $this->addColumn(self::COLUMN_CODE, ['label' => __($this->optionLabel), 'renderer' => $this->getSelect()]);
        $this->addColumn(self::COLUMN_WEIGHT, [
            'label' => __('Weight'),
            'class' => 'input-text admin__control-text required-entry validate-zero-or-greater',
            'style' => 'width: 80px',
        ]);
        $this->addColumn(self::COLUMN_SHARE, [
            'label' => __('Share'),
            'renderer' => $this->getLayout()->createBlock(ShareCell::class),
        ]);
        $this->_addAfter = false;
        $this->_addButtonLabel = (string)__('Add');
    }

    /**
     * Preselect the saved option of a row in the select template.
     *
     * @inheritDoc
     * @throws LocalizedException
     */
    #[\Override]
    protected function _prepareArrayRow(DataObject $row): void
    {
        $attributes = [];
        $code = $row->getData(self::COLUMN_CODE);
        if ($code !== null) {
            $attributes['option_' . $this->getSelect()->calcOptionHash($code)] = 'selected="selected"';
        }
        $row->setData('option_extra_attrs', $attributes);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    protected function _toHtml(): string
    {
        $init = ['Perspective_SalesDataGenerator/js/weighted-rows' => []];

        return '<div data-mage-init="' . $this->escapeHtmlAttr((string)json_encode($init)) . '">'
            . parent::_toHtml()
            . '</div>';
    }

    /**
     * Shared with _prepareArrayRow(): option hashes depend on the name and ID the cell template gives the select.
     *
     * @throws LocalizedException
     */
    private function getSelect(): OptionSelect
    {
        if ($this->select === null) {
            /** @var OptionSelect $select */
            $select = $this->getLayout()->createBlock(
                OptionSelect::class,
                '',
                ['data' => ['is_render_to_js_template' => true]]
            );
            $select->setOptions($this->optionSource->toOptionArray())
                ->setClass('admin__control-select')
                ->setExtraParams('data-role="code"');
            $this->select = $select;
        }

        return $this->select;
    }
}
