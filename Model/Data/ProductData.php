<?php

namespace Kanishka\AiAssistant\Model\Data;

use Kanishka\AiAssistant\Api\Data\ProductDataInterface;

class ProductData implements ProductDataInterface
{
    private string $sku = '';
    private string $urlKey = '';

    public function getSku(): string
    {
        return $this->sku;
    }

    public function setSku(string $sku): self
    {
        $this->sku = $sku;
        return $this;
    }

    public function getUrlKey(): string
    {
        return $this->urlKey;
    }

    public function setUrlKey(string $urlKey): self
    {
        $this->urlKey = $urlKey;
        return $this;
    }
}
