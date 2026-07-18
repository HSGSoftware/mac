<?php

namespace MacRadar\Services\Llm;

use MacRadar\Core\Settings;

class LlmFactory
{
    /**
     * settings.ai_provider değerine göre uygun istemciyi oluşturur.
     * @param string|null $override Test için sağlayıcıyı zorla (gemini/openai/custom)
     */
    public static function make(?string $override = null): LlmClientInterface
    {
        $provider = $override ?: Settings::get('ai_provider', 'gemini');

        if ($provider === 'gemini') {
            return new GeminiClient(
                (string) Settings::get('gemini_api_key', ''),
                (string) Settings::get('gemini_model', 'gemini-1.5-flash')
            );
        }

        // openai veya custom (ikisi de OpenAI-uyumlu; custom sadece base_url farklı)
        return new OpenAiClient(
            (string) Settings::get('openai_api_key', ''),
            (string) Settings::get('openai_base_url', 'https://api.openai.com/v1'),
            (string) Settings::get('openai_model', 'gpt-4o-mini')
        );
    }

    public static function providerName(?string $override = null): string
    {
        return $override ?: (string) Settings::get('ai_provider', 'gemini');
    }
}
