<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

class WebhookSignatureError extends \RuntimeException
{
    public function __construct(
        public readonly string $reason,
        string $message,
    ) {
        parent::__construct($message);
    }
}
