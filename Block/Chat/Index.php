<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Block\Chat;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;

class Index extends Template
{
    public function __construct(
        Context $context,
        private readonly CustomerSession $customerSession,
        private readonly FormKey $formKey,
        array $data = []
    ) {
        parent::__construct($context, $data);
    }

    public function getCustomerFirstname(): string
    {
        if ($this->customerSession->isLoggedIn()) {
            return (string) $this->customerSession->getCustomer()->getFirstname();
        }
        return '';
    }

    public function getFormKey(): string
    {
        return $this->formKey->getFormKey();
    }

    /**
     * URL for the existing anonymous REST chat endpoint (no controller needed).
     */
    public function getRestChatUrl(): string
    {
        $base = rtrim($this->_storeManager->getStore()->getBaseUrl(), '/');
        return $base . '/rest/V1/kanishka-ai/chat';
    }
}
