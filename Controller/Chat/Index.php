<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Chat;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\UrlInterface;
use Magento\Framework\View\Result\PageFactory;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly PageFactory $pageFactory,
        private readonly CustomerSession $customerSession,
        private readonly RedirectFactory $redirectFactory,
        private readonly UrlInterface $url
    ) {}

    public function execute()
    {
        if (!$this->customerSession->isLoggedIn()) {
            // Remember where to return after login
            $this->customerSession->setBeforeAuthUrl($this->url->getCurrentUrl());

            $redirect = $this->redirectFactory->create();
            $redirect->setPath('customer/account/login');
            return $redirect;
        }

        return $this->pageFactory->create();
    }
}
