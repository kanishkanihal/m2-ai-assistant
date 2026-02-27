<?php

namespace Kanishka\AiAssistant\Api;

use Kanishka\AiAssistant\Api\Data\ChatRequestInterface;
use Kanishka\AiAssistant\Api\Data\ChatResponseInterface;

interface ChatServiceInterface
{
    /**
     * Process a customer chat message using RAG pipeline.
     *
     * @param ChatRequestInterface $message
     * @return ChatResponseInterface
     */
    public function chat(ChatRequestInterface $message): ChatResponseInterface;
}
