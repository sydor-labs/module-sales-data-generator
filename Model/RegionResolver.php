<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Directory\Model\RegionFactory;

/**
 * Turns a directory region code into the ID and name addresses need, cached per run.
 */
class RegionResolver
{
    /**
     * @var array<string, array{region_id: int|null, region: string}>
     */
    private array $cache = [];

    public function __construct(
        private readonly RegionFactory $regionFactory
    ) {
    }

    /**
     * Region ID and name; a code missing from the directory is kept as a free-text region.
     *
     * @return array{region_id: int|null, region: string}
     */
    public function resolve(string $countryId, string $regionCode): array
    {
        $key = $countryId . '/' . $regionCode;
        if (!isset($this->cache[$key])) {
            $region = $this->regionFactory->create()->loadByCode($regionCode, $countryId);
            $this->cache[$key] = $region->getId()
                ? ['region_id' => (int)$region->getId(), 'region' => (string)$region->getName()]
                : ['region_id' => null, 'region' => $regionCode];
        }

        return $this->cache[$key];
    }
}
