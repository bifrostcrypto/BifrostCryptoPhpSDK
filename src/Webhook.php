<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

final class Webhook
{
    public const TOLERANCE_SECONDS = 300;

    /**
     * @param array<string, mixed> $headers
     * @return array<string, mixed>
     */
    public static function verify(
        string $rawBody,
        array $headers,
        string $key,
        ?int $now = null,
        int $toleranceSeconds = self::TOLERANCE_SECONDS,
    ): array {
        $signature = self::header($headers, 'x-bifrost-signature');
        $timestamp = self::header($headers, 'x-bifrost-timestamp');
        $delivery = self::header($headers, 'x-bifrost-delivery');

        if ($signature === null || $timestamp === null || $delivery === null) {
            throw new WebhookSignatureError('missing', 'Webhook signature headers are incomplete');
        }

        $now ??= time();
        if (!ctype_digit($timestamp) || abs($now - (int) $timestamp) > $toleranceSeconds) {
            throw new WebhookSignatureError('stale', 'Webhook timestamp is outside the allowed window');
        }

        $canonical = implode("\n", ['v1', $timestamp, $delivery, Signer::sha256($rawBody)]);
        $expected = 'v1=' . hash_hmac('sha256', $canonical, $key);
        if (!hash_equals($expected, $signature)) {
            throw new WebhookSignatureError('invalid', 'Webhook signature does not match');
        }

        try {
            $parsed = json_decode($rawBody, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new WebhookSignatureError('invalid_json', 'Webhook body is not JSON');
        }

        if (!is_array($parsed)) {
            throw new WebhookSignatureError('invalid_json', 'Webhook body is not JSON');
        }

        return $parsed;
    }

    /**
     * @param array<string, mixed> $headers
     */
    private static function header(array $headers, string $name): ?string
    {
        foreach ($headers as $key => $value) {
            if (strtolower((string) $key) !== $name) {
                continue;
            }
            if (is_array($value)) {
                $value = $value[0] ?? '';
            }
            $trimmed = trim((string) $value);

            return $trimmed === '' ? null : $trimmed;
        }

        return null;
    }
}
