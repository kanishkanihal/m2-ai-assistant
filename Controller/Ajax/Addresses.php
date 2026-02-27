<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Ajax;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;

class Addresses implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly Json $json,
        private readonly FormKey $formKey
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null;
    }

    public function validateForCsrf(RequestInterface $request): ?bool
    {
        try {
            $body = $this->json->unserialize($request->getContent());
            return ($body['form_key'] ?? '') === $this->formKey->getFormKey();
        } catch (\Exception $e) {
            return false;
        }
    }

    public function execute()
    {
        $result = $this->jsonFactory->create();

        if (!$this->customerSession->isLoggedIn()) {
            return $result->setHttpResponseCode(403)->setData(['error' => 'Not authenticated']);
        }

        try {
            $customer  = $this->customerSession->getCustomer();
            $addresses = [];

            foreach ($customer->getAddresses() as $address) {
                $street = $address->getStreet();

                $addresses[] = [
                    'id'         => (int) $address->getId(),
                    'firstname'  => (string) $address->getFirstname(),
                    'lastname'   => (string) $address->getLastname(),
                    'street'     => is_array($street) ? implode(', ', $street) : (string) $street,
                    'city'       => (string) $address->getCity(),
                    'region'     => (string) $address->getRegion(),
                    'postcode'   => (string) $address->getPostcode(),
                    'country_id' => (string) $address->getCountryId(),
                ];
            }

            return $result->setData(['addresses' => $addresses]);

        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['error' => $e->getMessage()]);
        }
    }
}
