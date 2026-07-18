<?php

namespace MacRadar\Services;

/**
 * cURL tabanlı basit HTTP istemcisi (scraper ve LLM istekleri için).
 */
class HttpClient
{
    public static function get(string $url, array $headers = [], int $timeout = 30): array
    {
        return self::request('GET', $url, null, $headers, $timeout);
    }

    public static function postJson(string $url, array $payload, array $headers = [], int $timeout = 60): array
    {
        $headers['Content-Type'] = 'application/json';
        return self::request('POST', $url, json_encode($payload, JSON_UNESCAPED_UNICODE), $headers, $timeout);
    }

    /**
     * @return array{status:int, body:string, error:?string}
     */
    public static function request(string $method, string $url, ?string $body, array $headers, int $timeout): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_ENCODING => '',
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $responseBody = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) ? curl_error($ch) : null;
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : $responseBody,
            'error' => $error,
        ];
    }
}
