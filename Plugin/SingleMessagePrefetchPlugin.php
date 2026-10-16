<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Plugin;

use Magento\Framework\Amqp\ConfigPool;
use Magento\Framework\Amqp\Queue;
use Magento\Framework\Amqp\QueueFactory;
use Magento\Framework\MessageQueue\QueueInterface;
use Magento\Framework\ObjectManagerInterface;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * Lets each batch consumer take one message at a time.
 */
class SingleMessagePrefetchPlugin
{
    public function __construct(
        private readonly ObjectManagerInterface $objectManager,
        private readonly ConfigPool $configPool
    ) {
    }

    /**
     * @param QueueFactory $subject
     * @param callable $proceed
     * @param string $queueName
     * @param string $connectionName
     * @return QueueInterface
     */
    public function aroundCreate(
        QueueFactory $subject,
        callable $proceed,
        $queueName,
        $connectionName
    ): QueueInterface {
        if ($queueName !== Scheduler::QUEUE) {
            return $proceed($queueName, $connectionName);
        }

        return $this->objectManager->create(Queue::class, [
            'amqpConfig' => $this->configPool->get($connectionName),
            'queueName' => $queueName,
            'prefetchCount' => 1,
        ]);
    }
}
