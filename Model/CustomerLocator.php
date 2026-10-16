<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Perspective\SalesDataGenerator\Model\ResourceModel\GeneratedEntities;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;

/**
 * Finds the customer created for a planned position without storing any mapping.
 */
class CustomerLocator
{
    public function __construct(
        private readonly PersonGeneratorFactory $personGeneratorFactory,
        private readonly RandomFactory $randomFactory,
        private readonly GeneratedEntities $generatedEntities
    ) {
    }

    /**
     * Tag the email of the customer at a position ends with.
     *
     * @param string $token job token
     * @param int $position
     * @return string
     */
    public static function tag(string $token, int $position): string
    {
        return $token . '.' . $position;
    }

    /**
     * Seed the customer at a position is created with.
     */
    public static function seedOf(int $seed, int $position): int
    {
        return Random::seedFor($seed, Segment::TYPE_CUSTOMER, $position);
    }

    /**
     * Rebuild the email of the customer at a position.
     */
    public function emailOf(int $seed, string $token, int $position): string
    {
        $random = $this->randomFactory->create();
        $random->seed(self::seedOf($seed, $position));

        return $this->personGeneratorFactory->create(['random' => $random])
            ->create(CustomerGenerator::EMAIL_DOMAIN, self::tag($token, $position))['email'];
    }

    /**
     * @param int $seed
     * @param string $token job token
     * @param list<int> $positions
     * @param int $websiteId
     * @return array<int, int> customer ID by position; positions without a customer are left out
     */
    public function findIds(int $seed, string $token, array $positions, int $websiteId): array
    {
        $emails = [];
        foreach (array_unique($positions) as $position) {
            $emails[$position] = $this->emailOf($seed, $token, $position);
        }
        $idsByEmail = $this->generatedEntities->findCustomerIds(array_values($emails), $websiteId);

        $ids = [];
        foreach ($emails as $position => $email) {
            $id = $idsByEmail[$email] ?? $this->generatedEntities->findCustomerIdByTag(
                self::tag($token, $position),
                $websiteId
            );
            if ($id !== null) {
                $ids[$position] = $id;
            }
        }

        return $ids;
    }
}
