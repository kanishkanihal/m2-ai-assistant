<?php

namespace Kanishka\AiAssistant\Api\Data;

interface ChatRequestInterface
{
    /**
     * @return string
     */
    public function getQuery(): string;

    /**
     * @param string $query
     * @return $this
     */
    public function setQuery(string $query): self;
}
