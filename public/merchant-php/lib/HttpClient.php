<?php
/**
 * 简单 HTTP 客户端
 */
final class HttpClient
{
    /**
     * @param array<string,scalar> $params
     */
    public static function postForm(string $url, array $params, int $timeout = 30): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init 失败');
        }

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        ]);

        return self::exec($ch);
    }

    public static function get(string $url, int $timeout = 15): string
    {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('curl_init 失败');
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPGET        => true,
        ]);

        return self::exec($ch);
    }

    private static function exec($ch): string
    {
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno !== 0 || $body === false) {
            throw new RuntimeException('HTTP 请求失败: ' . $error);
        }

        return (string) $body;
    }
}
