<?php

namespace MacRadar\Services\Llm;

interface LlmClientInterface
{
    /**
     * Sistem + kullanıcı promptu ile LLM'e istek atar, ham metin yanıt döndürür.
     * @param array $options ör. ['web_search' => true] — sağlayıcı destekliyorsa
     *                       internet araştırması (grounding) açılır.
     * @return array{text:string, tokens:?int, model:string}
     * @throws \RuntimeException API hatasında
     */
    public function complete(string $systemPrompt, string $userPrompt, array $options = []): array;

    /** Bağlantı testi: basit bir istek atıp başarılı olup olmadığını döndürür. */
    public function test(): array;
}
