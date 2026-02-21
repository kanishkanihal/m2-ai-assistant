<?php

namespace Kanishka\AiAssistant\Model;

use Kanishka\AiAssistant\Api\ChatServiceInterface;
use Kanishka\AiAssistant\Api\Data\ChatRequestInterface;
use Kanishka\AiAssistant\Api\Data\ChatResponseInterface;
use Kanishka\AiAssistant\Api\Data\ChatResponseInterfaceFactory;
use Kanishka\AiAssistant\Api\Data\ProductDataInterfaceFactory;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Psr\Log\LoggerInterface;

class ChatService implements ChatServiceInterface
{
    private const XML_PATH_OLLAMA_URL      = 'kanishka_ai/general/ollama_url';
    private const XML_PATH_ES_HOSTNAME     = 'catalog/search/elasticsearch7_server_hostname';
    private const XML_PATH_ES_PORT         = 'catalog/search/elasticsearch7_server_port';
    private const XML_PATH_ES_INDEX        = 'kanishka_ai/general/es_index';
    private const XML_PATH_CHAT_MODEL      = 'kanishka_ai/general/chat_model';
    private const XML_PATH_TOP_K              = 'kanishka_ai/general/top_k';
    private const XML_PATH_KEYWORD_TIMEOUT    = 'kanishka_ai/general/keyword_timeout';
    private const XML_PATH_CHAT_TIMEOUT       = 'kanishka_ai/general/chat_timeout';
    private const XML_PATH_SYSTEM_PROMPT      = 'kanishka_ai/prompts/system_prompt';
    private const XML_PATH_KEYWORD_PROMPT     = 'kanishka_ai/prompts/keyword_prompt';

    private ChatResponseInterfaceFactory $responseFactory;
    private ProductDataInterfaceFactory $productDataFactory;
    private ScopeConfigInterface $scopeConfig;
    private LoggerInterface $logger;

    public function __construct(
        ChatResponseInterfaceFactory $responseFactory,
        ProductDataInterfaceFactory $productDataFactory,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->responseFactory = $responseFactory;
        $this->productDataFactory = $productDataFactory;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    public function chat(ChatRequestInterface $message): ChatResponseInterface
    {
        $query = trim($message->getQuery());

        if ($query === '') {
            return $this->buildResponse('Please type a question so I can help you find what you need!', []);
        }

        try {
            // Step 1: Extract search keywords and price range from natural language query
            $extracted  = $this->extractKeywords($query);
            $keywords   = $extracted['keywords'] ?? $query;
            $minPrice   = $extracted['min_price'] ?? null;
            $maxPrice   = $extracted['max_price'] ?? null;

            if ($keywords === '') {
                $this->logger->error('ChatService: Keyword extraction failed, falling back to raw query.');
                $keywords = $query;
            }

            // Step 2: Search Elasticsearch using extracted keywords and price range
            $matchedProducts = $this->searchProducts($keywords, $minPrice, $maxPrice);

            if ($matchedProducts === null) {
                $this->logger->error('ChatService: Elasticsearch query failed.');
                return $this->buildResponse('Sorry, product search is temporarily unavailable. Please try again later.', []);
            }

            if (empty($matchedProducts)) {
                return $this->buildResponse(
                    "I'm sorry, I couldn't find any products matching your search. Try different keywords or browse our catalog.",
                    []
                );
            }

            // Step 3: Build prompt and generate AI response
            $prompt = $this->buildPrompt($query, $matchedProducts);

            $aiResponse = $this->generateChatResponse($prompt);
            if ($aiResponse === null) {
                $this->logger->error('ChatService: Ollama chat generation failed.');
                return $this->buildResponse('Sorry, I\'m having trouble generating a response. Please try again.', []);
            }

            // Step 4: Return response with product info
            $productList = array_map(function ($p) {
                $product = $this->productDataFactory->create();
                $product->setSku($p['sku']);
                $product->setUrlKey($p['url_key']);
                return $product;
            }, $matchedProducts);

            return $this->buildResponse($aiResponse, $productList);
        } catch (\Exception $e) {
            $this->logger->error('ChatService error: ' . $e->getMessage());
            return $this->buildResponse('Sorry, something went wrong. Please try again later.', []);
        }
    }

    private function extractKeywords(string $query): array
    {
        $body = json_encode([
            'model'  => $this->scopeConfig->getValue(self::XML_PATH_CHAT_MODEL),
            'messages' => [
                ['role' => 'system', 'content' => $this->scopeConfig->getValue(self::XML_PATH_KEYWORD_PROMPT)],
                ['role' => 'user',   'content' => $query],
            ],
            'stream' => false,
        ]);

        $response = $this->httpRequest('POST', $this->scopeConfig->getValue(self::XML_PATH_OLLAMA_URL) . '/api/chat', $body, (int) $this->scopeConfig->getValue(self::XML_PATH_KEYWORD_TIMEOUT));
        if ($response === null) {
            return ['keywords' => $query, 'min_price' => null, 'max_price' => null];
        }

        $data    = json_decode($response, true);
        $content = trim($data['message']['content'] ?? '');

        // Strip markdown code fences if the model wraps JSON in ```json ... ```
        $content = preg_replace('/^```(?:json)?\s*/i', '', $content);
        $content = preg_replace('/\s*```$/', '', trim($content));

        $parsed = json_decode($content, true);

        if (!is_array($parsed) || empty($parsed['keywords'])) {
            // Fallback: treat the whole response as plain keywords
            return ['keywords' => $content ?: $query, 'min_price' => null, 'max_price' => null];
        }

        return [
            'keywords'  => trim($parsed['keywords']),
            'min_price' => isset($parsed['min_price']) ? (float) $parsed['min_price'] : null,
            'max_price' => isset($parsed['max_price']) ? (float) $parsed['max_price'] : null,
        ];
    }

    private function searchProducts(string $query, ?float $minPrice, ?float $maxPrice): ?array
    {
        $filters = [
            ['term' => ['status' => 1]],
            ['term' => ['visibility' => 4]],
        ];

        if ($minPrice !== null || $maxPrice !== null) {
            $range = [];
            if ($minPrice !== null) {
                $range['gte'] = $minPrice;
            }
            if ($maxPrice !== null) {
                $range['lte'] = $maxPrice;
            }
            $filters[] = ['range' => ['price_0_1' => $range]];
        }

        $body = json_encode([
            'size' => (int) $this->scopeConfig->getValue(self::XML_PATH_TOP_K),
            '_source' => [
                'sku', 'url_key', 'name', 'description',
                'price_0_1',
                'color_value', 'climate_value', 'material_value',
                'style_general_value', 'size_value',
            ],
            'query' => [
                'bool' => [
                    'must' => [
                        [
                            'multi_match' => [
                                'query'     => $query,
                                'fields'    => [
                                    'name^3',
                                    'description',
                                    'color_value^2',
                                    'climate_value^2',
                                    'material_value^2',
                                    'style_general_value^2',
                                    'size_value^2',
                                ],
                                'type'     => 'cross_fields',
                                'operator' => 'and',
                            ],
                        ],
                    ],
                    'filter' => $filters,
                ],
            ],
        ]);

        $esHost  = $this->scopeConfig->getValue(self::XML_PATH_ES_HOSTNAME);
        $esPort  = $this->scopeConfig->getValue(self::XML_PATH_ES_PORT);
        $esUrl   = 'http://' . $esHost . ':' . $esPort;
        $esIndex = $this->scopeConfig->getValue(self::XML_PATH_ES_INDEX);

        $response = $this->httpRequest(
            'POST',
            $esUrl . '/' . $esIndex . '/_search',
            $body
        );

        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        $hits = $data['hits']['hits'] ?? [];

        $products = [];
        foreach ($hits as $hit) {
            $source = $hit['_source'];
            if (empty($source['sku'])) {
                continue;
            }
            $urlKey = $source['url_key'] ?? '';
            $rawPrice = $source['price_0_1'] ?? null;
            $products[] = [
                'sku'      => $source['sku'],
                'url_key'  => is_array($urlKey) ? ($urlKey[0] ?? '') : $urlKey,
                'name'     => $source['name'] ?? '',
                'price'    => $rawPrice !== null ? round((float) $rawPrice, 2) : null,
                'color'    => $this->flattenValue($source['color_value'] ?? ''),
                'climate'  => $this->flattenValue($source['climate_value'] ?? ''),
                'material' => $this->flattenValue($source['material_value'] ?? ''),
                'style'    => $this->flattenValue($source['style_general_value'] ?? ''),
                'size'     => $this->flattenValue($source['size_value'] ?? ''),
            ];
        }

        return $products;
    }

    private function flattenValue(mixed $value): string
    {
        if (is_array($value)) {
            return implode(', ', array_filter(array_map('strval', $value)));
        }
        return (string) $value;
    }

    private function buildPrompt(string $query, array $products): array
    {
        $productContext = '';
        foreach ($products as $i => $product) {
            $line = ($i + 1) . '. ' . $product['name'] . ' (SKU: ' . $product['sku'] . ')';
            $attrs = array_filter([
                $product['price']    !== null ? 'Price: $' . $product['price'] : '',
                $product['color']    ? 'Color: ' . $product['color'] : '',
                $product['climate']  ? 'Climate: ' . $product['climate'] : '',
                $product['material'] ? 'Material: ' . $product['material'] : '',
                $product['style']    ? 'Style: ' . $product['style'] : '',
                $product['size']     ? 'Sizes: ' . $product['size'] : '',
            ]);
            if ($attrs) {
                $line .= ' — ' . implode(', ', $attrs);
            }
            $productContext .= $line . "\n";
        }

        return [
            [
                'role'    => 'system',
                'content' => $this->scopeConfig->getValue(self::XML_PATH_SYSTEM_PROMPT) . "\n\nAvailable products:\n" . $productContext,
            ],
            [
                'role'    => 'user',
                'content' => $query,
            ],
        ];
    }

    private function generateChatResponse(array $messages): ?string
    {
        $body = json_encode([
            'model' => $this->scopeConfig->getValue(self::XML_PATH_CHAT_MODEL),
            'messages' => $messages,
            'stream' => false,
        ]);

        $response = $this->httpRequest('POST', $this->scopeConfig->getValue(self::XML_PATH_OLLAMA_URL) . '/api/chat', $body, (int) $this->scopeConfig->getValue(self::XML_PATH_CHAT_TIMEOUT));
        if ($response === null) {
            return null;
        }

        $data = json_decode($response, true);
        return $data['message']['content'] ?? null;
    }

    private function buildResponse(string $text, array $products): ChatResponseInterface
    {
        /** @var ChatResponseInterface $response */
        $response = $this->responseFactory->create();
        $response->setResponse($text);
        $response->setProducts($products);
        return $response;
    }

    private function httpRequest(string $method, string $url, ?string $body = null, int $timeout = 120): ?string
    {
        $ch = curl_init();

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CUSTOMREQUEST => $method,
        ]);

        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        }

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || $httpCode >= 400) {
            $this->logger->error(sprintf(
                'ChatService HTTP error: %s %s -> HTTP %d, error: %s',
                $method,
                $url,
                $httpCode,
                $error
            ));
            return null;
        }

        return $response;
    }
}
