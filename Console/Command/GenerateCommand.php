<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Console\Command;

use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Perspective\SalesDataGenerator\Console\JobRunner;
use Perspective\SalesDataGenerator\Model\Job\Parameters;
use Perspective\SalesDataGenerator\Model\Job\ParametersParser;
use Perspective\SalesDataGenerator\Model\Job\Planner;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class GenerateCommand extends Command
{
    private const string OPTION_GUEST_RATIO = 'guest-ratio';
    private const string OPTION_SKIP_STATISTICS = 'skip-statistics';
    private const string OPTION_QUEUE = 'queue';

    public function __construct(
        private readonly ParametersParser $parametersParser,
        private readonly Planner $planner,
        private readonly Scheduler $scheduler,
        private readonly JobRunner $jobRunner,
        private readonly State $appState
    ) {
        parent::__construct();
    }

    #[\Override]
    protected function configure(): void
    {
        $this->setName('sales-data:generate')
            ->setDescription('Fill the store with backdated test customers and orders (development only)')
            ->addOption(
                Parameters::CUSTOMERS,
                null,
                InputOption::VALUE_REQUIRED,
                'Customers to register (default: none)'
            )
            ->addOption(
                self::OPTION_GUEST_RATIO,
                null,
                InputOption::VALUE_REQUIRED,
                'Share of guest orders, 0..1',
                (string)ParametersParser::DEFAULT_GUEST_RATIO
            )
            ->addOption(
                Parameters::CATEGORIES,
                null,
                InputOption::VALUE_REQUIRED,
                'Categories to create before customers and orders (default: none)'
            )
            ->addOption(
                Parameters::PRODUCTS,
                null,
                InputOption::VALUE_REQUIRED,
                'Simple products to create before customers and orders (default: none)'
            )
            ->addOption(
                Parameters::ORDERS,
                null,
                InputOption::VALUE_REQUIRED,
                'Orders to place (default: none)'
            )
            ->addOption(
                Parameters::FROM,
                null,
                InputOption::VALUE_REQUIRED,
                'First day of the period, YYYY-MM-DD in store timezone (default: one year ago)'
            )
            ->addOption(
                Parameters::TO,
                null,
                InputOption::VALUE_REQUIRED,
                'Last day of the period, YYYY-MM-DD in store timezone (default: today)'
            )
            ->addOption(
                Parameters::STORE,
                null,
                InputOption::VALUE_REQUIRED,
                'Store view code (default: default store view)'
            )
            ->addOption(Parameters::SEED, null, InputOption::VALUE_REQUIRED, 'Random seed to reproduce a run')
            ->addOption(
                self::OPTION_SKIP_STATISTICS,
                null,
                InputOption::VALUE_NONE,
                'Do not refresh the core report statistics afterwards'
            )
            ->addOption(
                self::OPTION_QUEUE,
                null,
                InputOption::VALUE_NONE,
                'Queue the job for the "' . Scheduler::CONSUMER . '" consumer instead of running it here'
            );
    }

    #[\Override]
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if ($this->appState->getMode() === State::MODE_PRODUCTION) {
            $output->writeln('<error>Test data must not be generated in production mode.</error>');
            return Command::FAILURE;
        }

        try {
            $parameters = $this->parametersParser->parse([
                Parameters::CUSTOMERS => $input->getOption(Parameters::CUSTOMERS),
                Parameters::GUEST_RATIO => $input->getOption(self::OPTION_GUEST_RATIO),
                Parameters::CATEGORIES => $input->getOption(Parameters::CATEGORIES),
                Parameters::PRODUCTS => $input->getOption(Parameters::PRODUCTS),
                Parameters::ORDERS => $input->getOption(Parameters::ORDERS),
                Parameters::FROM => $input->getOption(Parameters::FROM),
                Parameters::TO => $input->getOption(Parameters::TO),
                Parameters::STORE => $input->getOption(Parameters::STORE),
                Parameters::SEED => $input->getOption(Parameters::SEED),
                Parameters::SKIP_STATISTICS => $input->getOption(self::OPTION_SKIP_STATISTICS),
            ]);
            $jobId = (int)$this->planner->create($parameters)->getId();
        } catch (LocalizedException $e) {
            $output->writeln('<error>' . $e->getMessage() . '</error>');
            return Command::INVALID;
        }

        $output->writeln(sprintf(
            '<info>Job #%d: %d categories, %d products, %d customers, %d orders, %s to %s.</info>',
            $jobId,
            $parameters->categories,
            $parameters->products,
            $parameters->customers,
            $parameters->orders,
            $parameters->from,
            $parameters->to
        ));
        if ($input->getOption(self::OPTION_QUEUE)) {
            $this->scheduler->start($jobId);
            $output->writeln(sprintf(
                'Queued. It runs once the consumer is running: bin/magento queue:consumers:start %s',
                Scheduler::CONSUMER
            ));
            return Command::SUCCESS;
        }

        return $this->jobRunner->run($jobId, $output);
    }
}
