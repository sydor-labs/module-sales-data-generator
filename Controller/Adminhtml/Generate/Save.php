<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Controller\Adminhtml\Generate;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\State;
use Magento\Framework\Exception\LocalizedException;
use Perspective\SalesDataGenerator\Model\Job\ParametersParser;
use Perspective\SalesDataGenerator\Model\Job\Planner;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * Creates a generation job from the options form and queues it.
 */
class Save extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'Perspective_SalesDataGenerator::generate';

    public function __construct(
        Context $context,
        private readonly ParametersParser $parametersParser,
        private readonly Planner $planner,
        private readonly Scheduler $scheduler,
        private readonly State $appState
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function execute(): Redirect
    {
        $redirect = $this->resultRedirectFactory->create()->setPath('*/*/index');
        if ($this->appState->getMode() === State::MODE_PRODUCTION) {
            $this->messageManager->addErrorMessage(__('Test data must not be generated in production mode.'));
            return $redirect;
        }

        try {
            $job = $this->planner->create($this->parametersParser->parse((array)$this->getRequest()->getPostValue()));
            $this->scheduler->start((int)$job->getId());
            $this->messageManager->addSuccessMessage(__(
                'Job #%1 is queued. It runs once the consumer is running: bin/magento queue:consumers:start %2',
                $job->getId(),
                Scheduler::CONSUMER
            ));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
            $this->_getSession()->setData('sales_data_generator_form', $this->getRequest()->getPostValue());
        }

        return $redirect;
    }
}
