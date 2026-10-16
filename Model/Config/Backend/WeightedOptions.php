<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model\Config\Backend;

use Magento\Config\Model\Config\Backend\Serialized\ArraySerialized;
use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Data\Collection\AbstractDb;
use Magento\Framework\Data\OptionSourceInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Model\Context;
use Magento\Framework\Model\ResourceModel\AbstractResource;
use Magento\Framework\Registry;
use Magento\Framework\Serialize\Serializer\Json;
use Perspective\SalesDataGenerator\Block\Adminhtml\System\Config\WeightedOptions as WeightedOptionsField;

/**
 * Validates option + weight rows: known options, no duplicates, non-negative weights, at least one above zero.
 */
class WeightedOptions extends ArraySerialized
{
    /**
     * @param Context $context
     * @param Registry $registry
     * @param ScopeConfigInterface $config
     * @param TypeListInterface $cacheTypeList
     * @param OptionSourceInterface $optionSource options a row may select
     * @param Json $json
     * @param AbstractResource|null $resource
     * @param AbstractDb|null $resourceCollection
     * @param array<string, mixed> $data
     * @param Json|null $serializer
     */
    public function __construct(
        Context $context,
        Registry $registry,
        ScopeConfigInterface $config,
        TypeListInterface $cacheTypeList,
        private readonly OptionSourceInterface $optionSource,
        private readonly Json $json,
        ?AbstractResource $resource = null,
        ?AbstractDb $resourceCollection = null,
        array $data = [],
        ?Json $serializer = null
    ) {
        parent::__construct(
            $context,
            $registry,
            $config,
            $cacheTypeList,
            $resource,
            $resourceCollection,
            $data,
            $serializer
        );
    }

    /**
     * @inheritDoc
     * @throws LocalizedException
     */
    #[\Override]
    public function beforeSave()
    {
        /** @var mixed $rows */
        $rows = $this->getValue();
        // The admin form posts rows, bin/magento config:set passes them as JSON.
        if (is_string($rows) && $rows !== '') {
            try {
                $rows = $this->json->unserialize($rows);
            } catch (\InvalidArgumentException) {
                throw new LocalizedException(__('%1: the value is not valid JSON.', $this->getPath()));
            }
        }
        $rows = is_array($rows) ? $rows : [];
        unset($rows['__empty']);

        $this->validate($rows);
        foreach ($rows as $rowId => $row) {
            $weight = (float)$row[WeightedOptionsField::COLUMN_WEIGHT];
            $rows[$rowId][WeightedOptionsField::COLUMN_WEIGHT] = (string)$weight;
        }
        $this->setValue($rows);

        return parent::beforeSave();
    }

    /**
     * @param array<string, mixed> $rows
     * @throws LocalizedException
     */
    private function validate(array $rows): void
    {
        $field = (string)($this->getFieldConfig()['label'] ?? $this->getPath());
        $labels = $this->getOptionLabels();
        $seen = [];
        $total = 0.0;

        foreach ($rows as $row) {
            $code = is_array($row) ? (string)($row[WeightedOptionsField::COLUMN_CODE] ?? '') : '';
            $weight = is_array($row) ? ($row[WeightedOptionsField::COLUMN_WEIGHT] ?? '') : '';
            if (!isset($labels[$code])) {
                throw new LocalizedException(
                    __('%1: "%2" is not available. Only active methods can be selected.', $field, $code)
                );
            }
            if (isset($seen[$code])) {
                throw new LocalizedException(__('%1: "%2" is listed more than once.', $field, $labels[$code]));
            }
            if (!is_numeric($weight) || (float)$weight < 0) {
                throw new LocalizedException(
                    __('%1: the weight of "%2" must be a number not less than 0.', $field, $labels[$code])
                );
            }
            $seen[$code] = true;
            $total += (float)$weight;
        }

        if ($total <= 0) {
            throw new LocalizedException(__('%1: at least one row must have a weight above 0.', $field));
        }
    }

    /**
     * Option labels keyed by value, optgroups flattened.
     *
     * @return array<string, string>
     */
    private function getOptionLabels(): array
    {
        $labels = [];
        $walk = static function (array $options) use (&$walk, &$labels): void {
            foreach ($options as $option) {
                if (is_array($option['value'])) {
                    $walk($option['value']);
                } else {
                    $labels[(string)$option['value']] = (string)$option['label'];
                }
            }
        };
        $walk($this->optionSource->toOptionArray());

        return $labels;
    }
}
