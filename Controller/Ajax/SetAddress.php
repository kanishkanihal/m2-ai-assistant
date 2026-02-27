<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Ajax;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Customer\Api\AddressRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Serialize\Serializer\Json;

class SetAddress implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly CheckoutSession $checkoutSession,
        private readonly AddressRepositoryInterface $addressRepository,
        private readonly Json $json,
        private readonly FormKey $formKey,
        private readonly PriceHelper $priceHelper
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
            $body      = $this->json->unserialize($this->request->getContent());
            $addressId = (int) ($body['address_id'] ?? 0);

            if (!$addressId) {
                return $result->setHttpResponseCode(400)->setData(['error' => 'Address ID is required']);
            }

            // Load and verify the address belongs to the current customer
            $address = $this->addressRepository->getById($addressId);
            if ((int) $address->getCustomerId() !== (int) $this->customerSession->getCustomerId()) {
                return $result->setHttpResponseCode(403)->setData(['error' => 'Address not found']);
            }

            // Import the address into the current quote's shipping address
            $quote           = $this->checkoutSession->getQuote();
            $shippingAddress = $quote->getShippingAddress();
            $shippingAddress->importCustomerAddressData($address);
            $shippingAddress->setCustomerAddressId($addressId);

            // Collect shipping rates for the selected address
            $shippingAddress->setCollectShippingRates(true);
            $shippingAddress->collectShippingRates();
            $quote->collectTotals()->save();

            // Build shipping methods list
            $shippingMethods = [];
            foreach ($shippingAddress->getAllShippingRates() as $rate) {
                if ($rate->getErrorMessage()) {
                    continue;
                }
                $price             = (float) $rate->getPrice();
                $shippingMethods[] = [
                    'code'            => $rate->getCarrier() . '_' . $rate->getMethod(),
                    'carrier_title'   => (string) $rate->getCarrierTitle(),
                    'method_title'    => (string) $rate->getMethodTitle(),
                    'price'           => $price,
                    'price_formatted' => $this->priceHelper->currency($price, true, false),
                ];
            }

            return $result->setData([
                'success'          => true,
                'shipping_methods' => $shippingMethods,
            ]);

        } catch (\Magento\Framework\Exception\NoSuchEntityException $e) {
            return $result->setHttpResponseCode(404)->setData(['error' => 'Address not found']);
        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['error' => $e->getMessage()]);
        }
    }
}
