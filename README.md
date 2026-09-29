# bifrostcrypto/sdk

PHP client for the Bifrost Crypto API (`https://bridge.bifrostcrypto.com`). Requires PHP 8.1.

```bash
composer require bifrostcrypto/sdk
```

```php
use BifrostCrypto\Sdk\Bifrost;
use function BifrostCrypto\Sdk\verify_webhook;

$bifrost = new Bifrost(
    apiKey: getenv('BIFROST_API_KEY'),
    signingSecret: getenv('BIFROST_SIGNING_SECRET') ?: null,
);

$payout = $bifrost->payouts->create([
    'wallet' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
    'crypto_currency' => 'USDT',
    'crypto_amount' => '100.50',
    'process_by' => 'crypto_amount',
    'reference_id' => 'order_12345',
    'network' => 'Ethereum',
    'feetakenfromamount' => 0,
    'webhook_url' => 'https://your-site.com/webhook',
], idempotencyKey: '6f1c0c4e-2b1a-4e3a-9c2d-1a2b3c4d5e6f');

$event = verify_webhook($rawBody, $headers, getenv('BIFROST_WEBHOOK_KEY'));
```

Amounts stay strings. Field names match the API. Pass `signingSecret` only when the key was created with request signing.
