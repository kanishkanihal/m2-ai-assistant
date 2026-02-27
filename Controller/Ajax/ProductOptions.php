<?php
declare(strict_types=1);

namespace Kanishka\AiAssistant\Controller\Ajax;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Framework\Serialize\Serializer\Json;

class ProductOptions implements HttpPostActionInterface, CsrfAwareActionInterface
{
    public function __construct(
        private readonly RequestInterface $request,
        private readonly JsonFactory $jsonFactory,
        private readonly CustomerSession $customerSession,
        private readonly ProductRepositoryInterface $productRepository,
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
            $body = $this->json->unserialize($this->request->getContent());
            $sku  = trim((string) ($body['sku'] ?? ''));

            if (!$sku) {
                return $result->setData(['options' => []]);
            }

            $product = $this->productRepository->get($sku, false, null, true);

            if ($product->getTypeId() !== 'configurable') {
                return $result->setData(['options' => []]);
            }

            /** @var \Magento\ConfigurableProduct\Model\Product\Type\Configurable $typeInstance */
            $typeInstance  = $product->getTypeInstance();
            $rawAttributes = $typeInstance->getConfigurableAttributesAsArray($product);

            $options = [];
            foreach ($rawAttributes as $attr) {
                $values = [];
                foreach ($attr['values'] as $val) {
                    $values[] = [
                        'value_id' => (string) $val['value_index'],
                        'label'    => (string) ($val['store_label'] ?? $val['label'] ?? $val['value_index']),
                    ];
                }
                $options[] = [
                    'attribute_id' => (string) $attr['attribute_id'],
                    'label'        => (string) $attr['label'],
                    'values'       => $values,
                ];
            }

            return $result->setData(['options' => $options]);

        } catch (\Exception $e) {
            return $result->setHttpResponseCode(500)->setData(['error' => $e->getMessage()]);
        }
    }
}
