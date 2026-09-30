<?php
declare(strict_types=1);

namespace App\Services;

use RuntimeException;

final class JwtService
{
    private string $secret;

    public function __construct(string $secret)
    {
        if (strlen($secret) < 32) {
            throw new RuntimeException('APP_KEY 至少需要 32 個字元。');
        }
        $this->secret = $secret;
    }

    public function issue(array $claims, int $ttl = 3600): string
    {
        $header = ['alg' => 'HS256', 'typ' => 'JWT'];
        $payload = $claims + ['iat' => time(), 'exp' => time() + $ttl];
        $parts = [$this->encode($header), $this->encode($payload)];
        $signature = hash_hmac('sha256', implode('.', $parts), $this->secret, true);
        $parts[] = $this->base64Url($signature);
        return implode('.', $parts);
    }

    public function verify(string $token): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new RuntimeException('JWT 格式錯誤。');
        }
        [$header, $payload, $signature] = $parts;
        $expected = $this->base64Url(hash_hmac('sha256', "{$header}.{$payload}", $this->secret, true));
        if (!hash_equals($expected, $signature)) {
            throw new RuntimeException('JWT 簽章無效。');
        }
        $claims = json_decode($this->decode($payload), true, 512, JSON_THROW_ON_ERROR);
        if (($claims['exp'] ?? 0) < time()) {
            throw new RuntimeException('JWT 已過期。');
        }
        return $claims;
    }

    private function encode(array $data): string
    {
        return $this->base64Url(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function decode(string $data): string
    {
        return (string) base64_decode(strtr($data, '-_', '+/'));
    }

    private function base64Url(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}
