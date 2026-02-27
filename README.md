# Kanishka_AiAssistant

AI-powered shopping assistant for Magento 2. Uses Elasticsearch for accurate product search and locally-hosted Ollama to generate natural language responses. No customer data leaves the server.

## Demo

[![AI Shopping Assistant Demo](https://img.youtube.com/vi/KkqDJW8Dxg0/maxresdefault.jpg)](https://www.youtube.com/watch?v=KkqDJW8Dxg0)

## Architecture

```
Customer query
      ↓
Elasticsearch multi_match
(name, description, color, climate, material, style, size)
      ↓
Top 5 matching products
      ↓
Ollama llama3.2 (formats friendly response)
      ↓
{ response, products[] }
```

| Component | Purpose |
|-----------|---------|
| Elasticsearch | Product search via multi_match across all relevant attributes |
| Ollama (`llama3.2`) | Generates natural language chat responses |

## Chat API

**POST** `/rest/V1/kanishka-ai/chat` — Anonymous (no auth required)

### Request
```bash
curl -k -X POST https://magento.local/rest/V1/kanishka-ai/chat \
  --header 'Content-Type: application/json' \
  --data '{
    "message": {
      "query": "I want a blue top for cool weather"
    }
  }'
```

### Response
```json
{
  "response": "We have some great options for a blue top in cool weather! The Iris Workout Top features a breathable design perfect for cooler temperatures.",
  "products": [
    { "sku": "WS03", "url_key": "iris-workout-top" },
    { "sku": "MT07", "url_key": "argus-all-weather-tank" }
  ]
}
```

### Supported query styles
| Query | Example |
|-------|---------|
| Keywords | `blue top cool` |
| Natural language | `I want a blue top for cool weather` |
| Single attribute | `Cool` |
| Size search | `XL jacket` |
| Material search | `cotton shorts` |

## Elasticsearch Query

Products are searched using `multi_match` with the following field weights:

| Field | Boost |
|-------|-------|
| `name` | 3x |
| `color_value` | 2x |
| `climate_value` | 2x |
| `material_value` | 2x |
| `style_general_value` | 2x |
| `size_value` | 2x |
| `description` | 1x |

- Filters: `status = 1`, `visibility = 4`
- Fuzziness: `AUTO` (handles typos)
- Returns top 5 results

## Ollama

Used only for response generation — not for search.

```bash
# Test chat from host
curl http://localhost:11434/api/chat -d '{
  "model": "llama3.2",
  "messages": [{"role": "user", "content": "Hello"}],
  "stream": false
}'

# List installed models
docker compose exec ollama ollama list
```

## Elasticsearch Reference

```bash
# Search products
curl http://localhost:9200/magento2_product_1/_search?pretty=true \
  --header 'Content-Type: application/json' \
  --data '{
    "size": 5,
    "_source": ["sku", "name", "color_value", "climate_value"],
    "query": {
      "bool": {
        "must": [{"multi_match": {"query": "blue top", "fields": ["name^3", "color_value^2"]}}],
        "filter": [{"term": {"status": 1}}, {"term": {"visibility": 4}}]
      }
    }
  }'

# List indices
curl http://localhost:9200/_aliases?pretty=true
```

## Magento REST API

```bash
# Generate admin token
curl -k -X POST https://magento.local/index.php/rest/V1/integration/admin/token \
  --header 'Content-Type: application/json' \
  --data '{"username":"admin","password":"Admin@123"}'

# Get product by SKU
curl -k https://magento.local/index.php/rest/V1/products/{sku} \
  --header 'Authorization: Bearer {token}'
```
