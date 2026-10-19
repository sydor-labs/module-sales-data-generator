<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\App\DeploymentConfig;
use Magento\Framework\Exception\FileSystemException;
use Magento\Framework\Exception\RuntimeException;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * How many generator consumers cron starts, as read from cron_consumers_runner in app/etc/env.php.
 *
 * Consumers started manually or by supervisor are not seen.
 *
 * @see \Magento\MessageQueue\Model\Cron\ConsumersRunner::run() cron rules this class repeats
 */
class CronConsumers
{
    public const string CONFIG_PATH = 'cron_consumers_runner';

    public function __construct(
        private readonly DeploymentConfig $deploymentConfig
    ) {
    }

    /**
     * Consumers cron keeps running: 0 when cron does not start this consumer at all.
     *
     * @throws FileSystemException
     * @throws RuntimeException
     */
    public function getProcesses(): int
    {
        if (!$this->deploymentConfig->get(self::CONFIG_PATH . '/cron_run', true)) {
            return 0;
        }
        $allowed = (array)$this->deploymentConfig->get(self::CONFIG_PATH . '/consumers', []);
        if ($allowed !== [] && !in_array(Scheduler::CONSUMER, $allowed, true)) {
            return 0;
        }
        $multiple = (array)$this->deploymentConfig->get(self::CONFIG_PATH . '/multiple_processes', []);

        return array_key_exists(Scheduler::CONSUMER, $multiple) ? max(0, (int)$multiple[Scheduler::CONSUMER]) : 1;
    }
}
