<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\DB\Adapter\DeadlockException;
use Magento\Framework\DB\Adapter\LockWaitException;

/**
 * Repeats an operation that failed on a deadlock or a lock wait timeout between parallel workers.
 *
 * Expects an atomic operation: one database transaction, which the database rolls back on failure.
 * Do not pass work with effects outside that transaction, such as emails, files or queued messages.
 */
class DeadlockRetry
{
    private const int ATTEMPTS = 3;

    /**
     * Longest pause before the next attempt, multiplied by the attempt number.
     */
    private const int MAX_PAUSE_MICROSECONDS = 200000; // 0.2 seconds

    /**
     * @template T
     * @param callable(): T $operation
     * @return T
     * @throws \Throwable the error of the last attempt, or any other error at once
     */
    public function execute(callable $operation): mixed
    {
        for ($attempt = 1;; $attempt++) {
            try {
                return $operation();
            } catch (\Throwable $e) {
                if ($attempt >= self::ATTEMPTS || !$this->isLockConflict($e)) {
                    throw $e;
                }
                // A random pause keeps the workers that collided from colliding again.
                // phpcs:ignore Magento2.Functions.DiscouragedFunction
                usleep(random_int(1, self::MAX_PAUSE_MICROSECONDS) * $attempt);
            }
        }
    }

    /**
     * Whether an error comes from a deadlock or a lock wait timeout.
     */
    public function isLockConflict(\Throwable $e): bool
    {
        // Repositories wrap database errors, e.g. in CouldNotSaveException.
        for ($error = $e; $error !== null; $error = $error->getPrevious()) {
            if ($error instanceof DeadlockException || $error instanceof LockWaitException) {
                return true;
            }
        }

        return false;
    }
}
