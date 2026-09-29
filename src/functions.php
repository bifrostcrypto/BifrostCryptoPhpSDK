<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

function verify_webhook(string $rawBody, array $headers, string $key, ?int $now = null): array
{
    return Webhook::verify($rawBody, $headers, $key, $now);
}
