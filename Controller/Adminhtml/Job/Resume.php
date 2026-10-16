<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Controller\Adminhtml\Job;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Exception\LocalizedException;
use Perspective\SalesDataGenerator\Model\Job\Scheduler;

/**
 * Continues a stalled or failed job from its saved progress.
 */
class Resume extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'Perspective_SalesDataGenerator::generate';

    public function __construct(
        Context $context,
        private readonly Scheduler $scheduler
    ) {
        parent::__construct($context);
    }

    /**
     * @inheritDoc
     */
    #[\Override]
    public function execute(): Redirect
    {
        $jobId = (int)$this->getRequest()->getParam('job_id');
        try {
            $this->scheduler->resume($jobId, true);
            $this->messageManager->addSuccessMessage(__('Job #%1 is queued to continue.', $jobId));
        } catch (LocalizedException $e) {
            $this->messageManager->addErrorMessage($e->getMessage());
        }

        return $this->resultRedirectFactory->create()->setPath('sales_data_generator/generate/index');
    }
}
