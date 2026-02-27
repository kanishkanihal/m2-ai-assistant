<?php

namespace Kanishka\AiAssistant\Model\Data;

use Kanishka\AiAssistant\Api\Data\ChatRequestInterface;

class ChatRequest implements ChatRequestInterface
{
    private string $query = '';

    public function getQuery(): string
    {
        return $this->query;
    }

    public function setQuery(string $query): self
    {
        $this->query = $query;
        return $this;
    }
}
