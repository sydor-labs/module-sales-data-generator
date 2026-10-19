<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Model;

use Magento\Catalog\Model\ResourceModel\Category as CategoryResource;
use Magento\Framework\Exception\LocalizedException;
use Perspective\SalesDataGenerator\Model\ResourceModel\JobCategory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Segment;

/**
 * Creates a job's category and records its position in one transaction.
 */
class JobCategoryCreator
{
    public function __construct(
        private readonly CatalogGenerator $catalogGenerator,
        private readonly CategoryResource $categoryResource,
        private readonly JobCategory $jobCategory,
        private readonly DeadlockRetry $deadlockRetry,
        private readonly Random $random
    ) {
    }

    /**
     * @param int $jobId
     * @param string $token
     * @param int $seed
     * @param int $position
     * @param int $parentId root category for departments, a department for the others
     * @param bool $isDepartment
     * @return int category ID
     * @throws LocalizedException
     */
    public function create(
        int $jobId,
        string $token,
        int $seed,
        int $position,
        int $parentId,
        bool $isDepartment
    ): int {
        return $this->deadlockRetry->execute(function () use (
            $jobId,
            $token,
            $seed,
            $position,
            $parentId,
            $isDepartment
        ): int {
            // Reseeded on every attempt, so a repeated category is the same.
            $this->random->seed(Random::seedFor($seed, Segment::TYPE_CATEGORY, $position));
            // Committed through the resource model, so Magento's after-commit callbacks still run.
            $this->categoryResource->beginTransaction();
            try {
                $categoryId = (int)$this->catalogGenerator
                    ->createCategory($token, $seed, $position, $parentId, $isDepartment)
                    ->getId();
                $this->jobCategory->save($jobId, $position, $categoryId);
                $this->categoryResource->commit();
            } catch (\Throwable $e) {
                $this->categoryResource->rollBack();
                throw $e;
            }

            return $categoryId;
        });
    }
}
