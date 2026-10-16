<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Framework\ObjectManager\ResetAfterRequestInterface;

/**
 * Empties the in-memory caches of Magento services after each batch.
 */
class RuntimeCacheCleaner
{
    /**
     * @param array<string, ResetAfterRequestInterface> $services shared instances whose state to reset
     * @param array<string, array{object: object, property: string}> $caches array properties to empty
     *     in services that cannot reset themselves
     */
    public function __construct(
        private readonly array $services = [],
        private readonly array $caches = []
    ) {
    }

    public function clean(): void
    {
        foreach ($this->services as $service) {
            $service->_resetState();
        }
        foreach ($this->caches as $cache) {
            $this->emptyProperty($cache['object'], $cache['property']);
        }
        gc_collect_cycles();
    }

    /**
     * Shared services are generated interceptors, so the property is looked up in the parent classes too.
     */
    private function emptyProperty(object $object, string $property): void
    {
        for ($class = new \ReflectionClass($object); $class !== false; $class = $class->getParentClass()) {
            if ($class->hasProperty($property) && $class->getProperty($property)->getDeclaringClass() == $class) {
                $class->getProperty($property)->setValue($object, []);
                return;
            }
        }
    }
}
