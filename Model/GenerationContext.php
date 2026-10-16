<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

/**
 * Whether the current process is generating data; plugins relax store rules only then.
 */
class GenerationContext
{
    /**
     * @var int
     */
    private int $depth = 0;

    /**
     * @template T
     * @param callable(): T $callback
     * @return T
     */
    public function run(callable $callback): mixed
    {
        $this->depth++;
        try {
            return $callback();
        } finally {
            $this->depth--;
        }
    }

    public function isActive(): bool
    {
        return $this->depth > 0;
    }
}
