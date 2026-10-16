<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Console;

use Perspective\SalesDataGenerator\Model\CustomerGenerator;
use Perspective\SalesDataGenerator\Model\Job;
use Perspective\SalesDataGenerator\Model\Job\Processor;
use Perspective\SalesDataGenerator\Model\Job\Progress;
use Perspective\SalesDataGenerator\Model\JobFactory;
use Perspective\SalesDataGenerator\Model\ResourceModel\Job as JobResource;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Runs a job in the current process batch by batch and prints its result.
 */
class JobRunner
{
    private const int MAX_REPORTED_ERRORS = 5;

    public function __construct(
        private readonly Processor $processor,
        private readonly JobFactory $jobFactory,
        private readonly JobResource $jobResource,
        private readonly Progress $progress
    ) {
    }

    /**
     * @return int command exit code
     */
    public function run(int $jobId, OutputInterface $output): int
    {
        $job = $this->loadJob($jobId);
        $done = $this->progress->get($job);
        $progress = new ProgressBar($output, $done['total']);
        $progress->setProgress($done['done']);

        try {
            while ($this->processor->processBatch($jobId, static fn () => $progress->advance())) {
                // Each batch saves its progress; the next one continues from it.
            }
        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln(sprintf(
                '<error>Job #%d stopped: %s</error> Resume it with: bin/magento sales-data:resume %1$d',
                $jobId,
                $e->getMessage()
            ));
            return Command::FAILURE;
        }
        $progress->finish();
        $output->writeln('');

        $job = $this->loadJob($jobId);
        if (!$job->isFinished()) {
            $output->writeln(sprintf('<comment>Job #%d is being processed by another process.</comment>', $jobId));
            return Command::FAILURE;
        }
        $this->printSummary($job, $output);
        $done = $this->progress->get($job);

        return $done['orders_done'] > $done['orders_failed'] || $job->getOrdersTotal() === 0
            ? Command::SUCCESS
            : Command::FAILURE;
    }

    public function printSummary(Job $job, OutputInterface $output): void
    {
        $done = $this->progress->get($job);
        if ($done['categories_total'] + $done['products_total'] > 0) {
            $output->writeln(sprintf(
                '<info>Job #%d: created %d categories and %d products.</info>',
                $job->getId(),
                $done['categories_done'],
                $done['products_done']
            ));
        }
        $output->writeln(sprintf(
            '<info>Job #%d: registered %d customers (password "%s"), placed %d of %d orders.</info>',
            $job->getId(),
            $done['customers_done'],
            CustomerGenerator::PASSWORD,
            $done['orders_done'] - $done['orders_failed'],
            $job->getOrdersTotal()
        ));

        $summary = $this->progress->getSummary($job);
        $labels = [
            Progress::SUMMARY_STATUS => 'Status',
            Progress::SUMMARY_PAYMENT => 'Payment',
            Progress::SUMMARY_SHIPPING => 'Shipping',
        ];
        foreach ($labels as $aspect => $label) {
            if (empty($summary[$aspect])) {
                continue;
            }
            arsort($summary[$aspect]);
            $output->writeln($label . ':');
            foreach ($summary[$aspect] as $value => $count) {
                $output->writeln(sprintf('  %-24s %d', $value, $count));
            }
        }

        if (!empty($summary[Progress::SUMMARY_ERRORS])) {
            arsort($summary[Progress::SUMMARY_ERRORS]);
            $output->writeln(sprintf('<comment>%d orders failed:</comment>', $done['orders_failed']));
            $errors = array_slice($summary[Progress::SUMMARY_ERRORS], 0, self::MAX_REPORTED_ERRORS, true);
            foreach ($errors as $message => $count) {
                $output->writeln(sprintf('  %dx %s', $count, $message));
            }
        }
    }

    private function loadJob(int $jobId): Job
    {
        $job = $this->jobFactory->create();
        $this->jobResource->load($job, $jobId);

        return $job;
    }
}
