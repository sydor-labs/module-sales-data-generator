<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Block\Adminhtml\System\Config;

use Magento\Backend\Block\Template\Context;
use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Magento\Framework\View\Helper\SecureHtmlRenderer;
use Perspective\SalesDataGenerator\Model\Config;
use Perspective\SalesDataGenerator\Model\CronConsumers;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * Parallel Workers input with a warning when cron starts fewer consumers than there are workers.
 *
 * The warning does not block generation: consumers may also be started manually or by supervisor.
 */
class WorkersField extends Field
{
    /**
     * @param Context $context
     * @param Config $config
     * @param CronConsumers $cronConsumers
     * @param array<string, mixed> $data
     * @param SecureHtmlRenderer|null $secureRenderer
     */
    public function __construct(
        Context $context,
        private readonly Config $config,
        private readonly CronConsumers $cronConsumers,
        array $data = [],
        ?SecureHtmlRenderer $secureRenderer = null
    ) {
        parent::__construct($context, $data, $secureRenderer);
    }

    /**
     * @inheritDoc
     * @throws FileSystemException
     * @throws RuntimeException
     */
    #[\Override]
    protected function _getElementHtml(AbstractElement $element): string
    {
        $html = parent::_getElementHtml($element);
        $workers = $this->config->getWorkers();
        $processes = $this->cronConsumers->getProcesses();
        if ($processes >= $workers) {
            return $html;
        }

        $warning = $processes === 0
            ? __(
                'Parallel Workers is %1, but cron does not start %2 consumers (%3 in app/etc/env.php). '
                . 'Start the consumers manually or allow cron to start them; otherwise the jobs wait in the queue.',
                $workers,
                Scheduler::CONSUMER,
                CronConsumers::CONFIG_PATH
            )
            : __(
                'Parallel Workers is %1, but cron starts at most %2 %3 consumer(s) (%4 in app/etc/env.php). '
                . 'Start more consumers manually or raise that value; otherwise the chains are processed %2 at a time.',
                $workers,
                $processes,
                Scheduler::CONSUMER,
                CronConsumers::CONFIG_PATH . '/multiple_processes'
            );

        return $html . '<div class="message message-warning">' . $this->escapeHtml($warning) . '</div>';
    }
}
