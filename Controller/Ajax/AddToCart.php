<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Ajax;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Checkout\Model\Cart;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\DataObject;
use Magento\Framework\Serialize\Serializer\Json;

class AddToCart implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly Cart $cart,
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
            $body           = $this->json->unserialize($this->request->getContent());
            $sku            = trim((string) ($body['sku'] ?? ''));
            $qty            = max(1, (int) ($body['qty'] ?? 1));
            $superAttribute = $body['super_attribute'] ?? [];

            if (!$sku) {
                return $result->setHttpResponseCode(400)->setData(['error' => 'SKU is required']);
            }

            $product    = $this->productRepository->get($sku, false, null, true);
            $buyRequest = new DataObject([
                'qty'             => $qty,
                'super_attribute' => $superAttribute,
            ]);

            $this->cart->addProduct($product, $buyRequest);
            $this->cart->save();

            return $result->setData([
                'success'    => true,
                'cart_count' => (int) $this->cart->getQuote()->getItemsQty(),
            ]);

        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            return $result->setHttpResponseCode(400)->setData(['error' => $e->getMessage()]);
        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['error' => $e->getMessage()]);
        }
    }
}
