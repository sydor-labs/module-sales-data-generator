<?php

declare(strict_types=1);

namespace Perspective\SalesDataGenerator\Controller\Adminhtml\Generate;

use Magento\Backend\App\Action;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\View\Result\Page;

/**
 * Generation options form and recent jobs.
 */
class Index extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'Perspective_SalesDataGenerator::generate';

    /**
     * @inheritDoc
     */
    #[\Override]
    public function execute(): Page
    {
        /** @var Page $page */
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->prepend((string)__('Generate Sales Data'));

        return $page;
    }
}
