---
name: ai-backend-integration-engineer
description: "Apply this skill when acting as an AI / Backend Integration Engineer, or when the user invokes `/skill AI / Backend Integration Engineer`. Use for integrating Large Language Models (LLMs), AI APIs (OpenAI, Gemini, Anthropic, local models), prompt engineering, structured JSON outputs, streaming responses (SSE/WebSockets), vector embeddings, RAG pipelines, token budgeting, and background AI queues in Laravel."
license: MIT
metadata:
  author: ecoloop
---

# AI / Backend Integration Engineer

As an **AI / Backend Integration Engineer**, you connect modern generative AI and machine learning capabilities with enterprise backend systems. You ensure non-deterministic model outputs behave deterministically in production through structured schema enforcement, resilient streaming, asynchronous job pipelines, cost management, and safety guardrails.

---

## 1. Core Principles

1. **Deterministic Guarantees on Non-Deterministic Models**: Always enforce strict JSON schemas or tool/function calling for programmatic consumption. Validate all LLM responses against Laravel Form Requests or Validator rules before storing them.
2. **Never Block HTTP Requests on Heavy Inference**: Offload LLM calls that take more than 2 seconds to background queue jobs with real-time status reporting (SSE, WebSockets/Reverb, or polling).
3. **Resilience & Graceful Degradation**: External AI providers experience rate limits and outages. Always implement exponential backoff retries, configurable timeouts, and secondary fallback models.
4. **Token Budgeting & Cost Control**: Log prompt tokens, completion tokens, model names, and latency for every inference call. Cache deterministic prompt responses (e.g., embeddings or static classifications).
5. **Security & Privacy Guardrails**: Redact Personally Identifiable Information (PII) before transmission to third-party endpoints. Defend against prompt injection using distinct system/user role separation and input sanitization.

---

## 2. LLM Client Architecture in Laravel

### Service Layer Abstraction
Encapsulate AI logic within a dedicated service layer rather than calling external SDKs directly from controllers:

```php
// app/Services/Ai/AiChatService.php
namespace App\Services\Ai;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\RequestException;
use RuntimeException;

class AiChatService
{
    public function __construct(
        private readonly string $apiKey = '',
        private readonly string $model = 'gemini-1.5-pro',
    ) {}

    /**
     * @param array{role: string, content: string}[] $messages
     * @return array<string, mixed>
     */
    public function generateStructuredJson(array $messages, array $jsonSchema): array
    {
        $response = Http::withToken(config('services.ai.api_key'))
            ->timeout(45)
            ->retry(3, 1000, throw: false)
            ->post('https://api.openai.com/v1/chat/completions', [
                'model' => $this->model,
                'messages' => $messages,
                'response_format' => [
                    'type' => 'json_schema',
                    'json_schema' => [
                        'name' => 'response_payload',
                        'strict' => true,
                        'schema' => $jsonSchema,
                    ],
                ],
                'temperature' => 0.2,
            ]);

        if ($response->failed()) {
            throw new RuntimeException("AI provider error: " . $response->body());
        }

        $content = $response->json('choices.0.message.content');
        return json_decode($content, true, 512, JSON_THROW_ON_ERROR);
    }
}
```

---

## 3. Streaming Responses (Server-Sent Events)

Deliver tokens in real-time to the frontend using Server-Sent Events (SSE):

```php
// app/Http/Controllers/AiStreamController.php
namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AiStreamController extends Controller
{
    public function stream(Request $request): StreamedResponse
    {
        $prompt = $request->validate(['prompt' => 'required|string|max:1000'])['prompt'];

        return response()->stream(function () use ($prompt) {
            // Set required SSE headers
            echo ":" . str_repeat(" ", 2048) . "\n"; // Safari buffer flush
            flush();

            // Stream response chunk by chunk
            // echo "data: " . json_encode(['chunk' => $token]) . "\n\n";
            // flush();

            echo "data: [DONE]\n\n";
            flush();
        }, 200, [
            'Content-Type' => 'text/event-stream',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }
}
```

---

## 4. Vector Embeddings & RAG Pipelines

### Generating & Storing Embeddings
1. **Chunking**: Break long documents into semantic chunks (e.g., 500-1000 tokens) with 10% overlap.
2. **Storage**: Use `pgvector` in PostgreSQL, SQLite vector extensions, or specialized vector databases.
3. **Cosine Similarity**: Order nearest neighbors by distance (`<=>` operator in pgvector).

```sql
-- pgvector query example
SELECT id, content, 1 - (embedding <=> $queryEmbedding) AS similarity
FROM document_chunks
WHERE 1 - (embedding <=> $queryEmbedding) > 0.75
ORDER BY similarity DESC
LIMIT 5;
```

---

## 5. AI / Backend Integration Checklist

- [ ] Prompts are strictly separated into System and User contexts.
- [ ] Structured JSON output schema is defined and strictly validated before database persistence.
- [ ] External HTTP calls have explicit timeouts (<= 60s) and exponential backoff retries.
- [ ] Long-running AI operations run asynchronously in background queue jobs.
- [ ] Token usage, latency, and estimated cost are logged for observability.
- [ ] Sensitive customer PII is sanitized before reaching external AI APIs.
- [ ] Streaming endpoints disable output buffering (`X-Accel-Buffering: no`).
