<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

final class Signer
{
    public static function sha256(string $body): string
    {
        return hash('sha256', $body);
    }

    public static function sign(
        string $secret,
        string $method,
        string $path,
        string $query,
        string $timestamp,
        string $nonce,
        string $body,
    ): string {
        $canonical = implode("\n", [
            $method,
            $path,
            $query,
            $timestamp,
            $nonce,
            self::sha256($body),
        ]);

        return hash_hmac('sha256', $canonical, $secret);
    }
}
