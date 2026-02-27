<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Ajax;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Helper\Image as ImageHelper;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Pricing\Helper\Data as PriceHelper;
use Magento\Framework\Serialize\Serializer\Json;

class ProductInfo implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly ImageHelper $imageHelper,
        private readonly PriceHelper $priceHelper,
        private readonly Json $json,
        private readonly FormKey $formKey
    ) {}

    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return null; // Return null = respond with setData error rather than exception
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
            $body = $this->json->unserialize($this->request->getContent());
            $skus = $body['skus'] ?? [];

            if (!is_array($skus) || empty($skus)) {
                return $result->setData(['products' => []]);
            }

            $products = [];
            foreach ($skus as $sku) {
                try {
                    $product  = $this->productRepository->get((string) $sku, false, null, true);
                    $imageUrl = $this->imageHelper
                        ->init($product, 'product_thumbnail_image')
                        ->getUrl();
                    $price    = $product->getFinalPrice();

                    $products[] = [
                        'sku'             => $product->getSku(),
                        'name'            => $product->getName(),
                        'type_id'         => $product->getTypeId(),
                        'image_url'       => $imageUrl,
                        'price'           => $price,
                        'price_formatted' => $this->priceHelper->currency($price, true, false),
                    ];
                } catch (\Exception $e) {
                    // Skip products that can't be loaded
                }
            }

            return $result->setData(['products' => $products]);

        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['error' => $e->getMessage()]);
        }
    }
}
