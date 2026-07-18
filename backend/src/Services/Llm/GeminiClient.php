<?php

namespace MacRadar\Services\Llm;

use MacRadar\Services\HttpClient;

/**
 * Google Gemini API istemcisi (generateContent).
 */
class GeminiClient implements LlmClientInterface
{
    private string $apiKey;
    private string $model;

    public function __construct(string $apiKey, string $model = 'gemini-1.5-flash')
    {
        $this->apiKey = $apiKey;
        $this->model = $model;
    }

    public function complete(string $systemPrompt, string $userPrompt): array
    {
        if ($this->apiKey === '') {
            throw new \RuntimeException('Gemini API anahtarı tanımlı değil.');
        }
        $url = "https://generativelanguage.googleapis.com/v1beta/models/{$this->model}:generateContent?key=" . urlencode($this->apiKey);
        $payload = [
            'systemInstruction' => ['parts' => [['text' => $systemPrompt]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $userPrompt]]]],
            'generationConfig' => [
                'temperature' => 0.4,
                'responseMimeType' => 'application/json',
                // Tüm marketlerin analizi uzun JSON üretir; kesilmesin
                'maxOutputTokens' => 8192,
            ],
        ];
        $res = HttpClient::postJson($url, $payload, [], 90);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('Gemini hatası (HTTP ' . $res['status'] . '): ' . mb_substr($res['body'], 0, 300));
        }
        $data = json_decode($res['body'], true);
        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? '';
        if ($text === '') {
            throw new \RuntimeException('Gemini boş yanıt döndürdü.');
        }
        $tokens = $data['usageMetadata']['totalTokenCount'] ?? null;
        return ['text' => $text, 'tokens' => $tokens, 'model' => $this->model];
    }

    public function test(): array
    {
        $r = $this->complete('Sen bir test asistanısın. Yalnızca JSON döndür.', 'Bana {"ok":true} JSON döndür.');
        return ['ok' => true, 'model' => $this->model, 'sample' => mb_substr($r['text'], 0, 120)];
    }
}
