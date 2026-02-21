<?php

namespace Kanishka\AiAssistant\Model\Data;

use Kanishka\AiAssistant\Api\Data\ChatResponseInterface;

class ChatResponse implements ChatResponseInterface
{
    private string $response = '';
    private array $products = [];

    public function getResponse(): string
    {
        return $this->response;
    }

    public function setResponse(string $response): self
    {
        $this->response = $response;
        return $this;
    }

    public function getProducts(): array
    {
        return $this->products;
    }

    public function setProducts(array $products): self
    {
        $this->products = $products;
        return $this;
    }
}
