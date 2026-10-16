<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Console\Command;

use Magento\Framework\Exception\LocalizedException;
use Perspective\SalesDataGenerator\Console\JobRunner;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ResumeCommand extends Command
{
    private const string ARGUMENT_JOB_ID = 'job_id';
    private const string OPTION_QUEUE = 'queue';

    public function __construct(
        private readonly Scheduler $scheduler,
        private readonly JobRunner $jobRunner
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('sales-data:resume')
            ->setDescription('Continue an interrupted or failed sales data generation job from its saved progress')
            ->addArgument(self::ARGUMENT_JOB_ID, InputArgument::REQUIRED, 'Job ID')
            ->addOption(
                self::OPTION_QUEUE,
                null,
                InputOption::VALUE_NONE,
                'Hand the job to the "' . Scheduler::CONSUMER . '" consumer instead of running it here'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $jobId = (int)$input->getArgument(self::ARGUMENT_JOB_ID);
        try {
            if ($input->getOption(self::OPTION_QUEUE)) {
                $this->scheduler->resume($jobId, true);
                $output->writeln(sprintf('<info>Job #%d queued.</info>', $jobId));
                return Command::SUCCESS;
            }
            $this->scheduler->markResumed($jobId);
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }

        return $this->jobRunner->run($jobId, $output);
    }
}
