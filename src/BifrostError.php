<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

class BifrostError extends \RuntimeException
{
    public function __construct(
        public readonly int $status,
        public readonly string $error,
        string $message,
        public readonly mixed $details = null,
        public readonly mixed $body = null,
        public readonly ?int $retryAfterSeconds = null,
    ) {
        parent::__construct($message);
    }
}
