<?php

namespace Kanishka\AiAssistant\Api\Data;

interface ChatResponseInterface
{
    /**
     * @return string
     */
    public function getResponse(): string;

    /**
     * @param string $response
     * @return $this
     */
    public function setResponse(string $response): self;

    /**
     * @return \Kanishka\AiAssistant\Api\Data\ProductDataInterface[]
     */
    public function getProducts(): array;

    /**
     * @param \Kanishka\AiAssistant\Api\Data\ProductDataInterface[] $products
     * @return $this
     */
    public function setProducts(array $products): self;
}
