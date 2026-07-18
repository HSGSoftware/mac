<?php

namespace MacRadar\Services\Llm;

use MacRadar\Services\HttpClient;

/**
 * OpenAI uyumlu istemci. base_url ayarlanabilir olduğundan OpenAI'nin yanı sıra
 * OpenRouter, Groq, Together, Ollama, LM Studio ve OpenAI-uyumlu her custom LLM
 * uç noktasıyla çalışır (Chat Completions formatı).
 */
class OpenAiClient implements LlmClientInterface
{
    private string $apiKey;
    private string $baseUrl;
    private string $model;

    public function __construct(string $apiKey, string $baseUrl = 'https://api.openai.com/v1', string $model = 'gpt-4o-mini')
    {
        $this->apiKey = $apiKey;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->model = $model;
    }

    // Not: web_search seçeneği Chat Completions formatında desteklenmez;
    // internet araştırması için Gemini sağlayıcısını kullanın.
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): array
    {
        $url = $this->baseUrl . '/chat/completions';
        $payload = [
            'model' => $this->model,
            'temperature' => 0.4,
            'response_format' => ['type' => 'json_object'],
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt],
            ],
        ];
        $headers = [];
        if ($this->apiKey !== '') {
            $headers['Authorization'] = 'Bearer ' . $this->apiKey;
        }
        $res = HttpClient::postJson($url, $payload, $headers, 90);
        if ($res['status'] !== 200) {
            // Bazı custom uçlar response_format desteklemez; bir kez onsuz dene.
            unset($payload['response_format']);
            $res = HttpClient::postJson($url, $payload, $headers, 90);
            if ($res['status'] !== 200) {
                throw new \RuntimeException('OpenAI/custom hatası (HTTP ' . $res['status'] . '): ' . mb_substr($res['body'], 0, 300));
            }
        }
        $data = json_decode($res['body'], true);
        $text = $data['choices'][0]['message']['content'] ?? '';
        if ($text === '') {
            throw new \RuntimeException('LLM boş yanıt döndürdü.');
        }
        $tokens = $data['usage']['total_tokens'] ?? null;
        return ['text' => $text, 'tokens' => $tokens, 'model' => $data['model'] ?? $this->model];
    }

    public function test(): array
    {
        $r = $this->complete('Sen bir test asistanısın. Yalnızca JSON döndür.', 'Bana {"ok":true} JSON döndür.');
        return ['ok' => true, 'model' => $r['model'], 'base_url' => $this->baseUrl, 'sample' => mb_substr($r['text'], 0, 120)];
    }
}
