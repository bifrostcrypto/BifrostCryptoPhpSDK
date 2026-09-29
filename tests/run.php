<?php

declare(strict_types=1);

$failures = 0;

spl_autoload_register(static function (string $class): void {
    $prefix = 'BifrostCrypto\\Sdk\\';
    if (!str_starts_with($class, $prefix)) {
        return;
    }
    $relative = substr($class, strlen($prefix));
    $path = dirname(__DIR__) . '/src/' . str_replace('\\', '/', $relative) . '.php';
    if (is_file($path)) {
        require $path;
    }
});

require dirname(__DIR__) . '/src/functions.php';

use BifrostCrypto\Sdk\Bifrost;
use BifrostCrypto\Sdk\BifrostError;
use BifrostCrypto\Sdk\Signer;
use BifrostCrypto\Sdk\WebhookSignatureError;
use function BifrostCrypto\Sdk\verify_webhook;

$contract = dirname(__DIR__) . '/contract';
$signature = json_decode((string) file_get_contents($contract . '/signature.json'), true, 512, JSON_THROW_ON_ERROR);
$webhook = json_decode((string) file_get_contents($contract . '/webhook.json'), true, 512, JSON_THROW_ON_ERROR);
$http = json_decode((string) file_get_contents($contract . '/http.json'), true, 512, JSON_THROW_ON_ERROR);

function check(bool $ok, string $message): void
{
    global $failures;
    if (!$ok) {
        $failures++;
        fwrite(STDERR, "FAIL {$message}\n");
    }
}

function jsonResponse(int $status, mixed $body): array
{
    return [
        'status' => $status,
        'headers' => ['content-type' => 'application/json'],
        'body' => json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
    ];
}

function ok(): array
{
    return jsonResponse(200, ['message' => 'ok']);
}

function samplePayout(string $reference): array
{
    return [
        'wallet' => '0x742d35Cc6634C0532925a3b844Bc9e7595f0bEb',
        'crypto_currency' => 'USDT',
        'crypto_amount' => '1.00',
        'process_by' => 'crypto_amount',
        'reference_id' => $reference,
        'network' => 'Ethereum',
        'feetakenfromamount' => 0,
        'webhook_url' => 'https://example.com/hook',
    ];
}

foreach ($signature['vectors'] as $vector) {
    $actual = Signer::sign(
        $signature['secret'],
        $vector['method'],
        $vector['path'],
        $vector['query'],
        $vector['timestamp'],
        $vector['nonce'],
        $vector['body'],
    );
    check($actual === $vector['signature'], 'signature ' . $vector['id']);
}

$headers = [
    'X-Bifrost-Signature' => $webhook['signature'],
    'X-Bifrost-Timestamp' => $webhook['timestamp'],
    'X-Bifrost-Delivery' => $webhook['delivery'],
];
$event = verify_webhook($webhook['raw_body'], $headers, $webhook['key'], $webhook['now']);
check($event['event'] === $webhook['expected_event'], 'webhook valid');
check(($event['data']['reference_id'] ?? null) === 'order_12345', 'webhook reference');

$edge = verify_webhook($webhook['raw_body'], $headers, $webhook['key'], $webhook['within_window_now']);
check($edge['event'] === $webhook['expected_event'], 'webhook window edge');

try {
    verify_webhook($webhook['raw_body'], $headers, $webhook['key'], $webhook['stale_now']);
    check(false, 'webhook stale should throw');
} catch (WebhookSignatureError $error) {
    check($error->reason === 'stale', 'webhook stale reason');
}

try {
    $bad = $headers;
    $bad['X-Bifrost-Signature'] = $webhook['bad_signature'];
    verify_webhook($webhook['raw_body'], $bad, $webhook['key'], $webhook['now']);
    check(false, 'webhook bad signature should throw');
} catch (WebhookSignatureError $error) {
    check($error->reason === 'invalid', 'webhook invalid reason');
}

try {
    verify_webhook($webhook['raw_body'], [], $webhook['key'], $webhook['now']);
    check(false, 'webhook missing should throw');
} catch (WebhookSignatureError $error) {
    check($error->reason === 'missing', 'webhook missing reason');
}

check(Bifrost::BASE_URL === $http['base_url'], 'base url');

$calls = [];
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    handler: function (string $method, string $url, array $headers, ?string $body) use (&$calls, $http): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return jsonResponse($http['balances_ok']['status'], $http['balances_ok']['body']);
    },
);
$balances = $bifrost->balances->list();
check(($balances['balances']['balance_tether'] ?? null) === '1000.00000000', 'balances amount');
check($calls[0]['method'] === 'GET', 'unsigned method');
check($calls[0]['url'] === $http['base_url'] . $http['paths']['balances'], 'unsigned url');
check($calls[0]['headers']['X-Bifrost-Invoke'] === $http['api_key'], 'api key header');
check(!isset($calls[0]['headers']['X-Bifrost-Signature']), 'no signature without secret');
check($calls[0]['body'] === null, 'get has no body');

$vector = null;
foreach ($signature['vectors'] as $item) {
    if ($item['id'] === 'post_with_body') {
        $vector = $item;
    }
}
$calls = [];
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    signingSecret: $signature['secret'],
    clock: fn () => (int) $vector['timestamp'],
    nonce: fn () => $vector['nonce'],
    handler: function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return jsonResponse(200, ['message' => 'payment_processing_completed', 'results' => []]);
    },
);
$bifrost->payouts->create(json_decode($vector['body'], true), 'idem-1');
check($calls[0]['body'] === $vector['body'], 'signed body bytes ' . $calls[0]['body']);
check($calls[0]['headers']['X-Bifrost-Signature'] === $vector['signature'], 'signed payout signature');
check($calls[0]['headers']['Idempotency-Key'] === 'idem-1', 'idempotency header');

$vector = null;
foreach ($signature['vectors'] as $item) {
    if ($item['id'] === 'get_with_query') {
        $vector = $item;
    }
}
$calls = [];
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    signingSecret: $signature['secret'],
    clock: fn () => (int) $vector['timestamp'],
    nonce: fn () => $vector['nonce'],
    handler: function (string $method, string $url, array $headers, ?string $body) use (&$calls): array {
        $calls[] = compact('method', 'url', 'headers', 'body');
        return ok();
    },
);
$bifrost->prices->get(['currency1' => 'USDT', 'currency2' => 'USD']);
check($calls[0]['url'] === $http['base_url'] . $vector['path'] . '?' . $vector['query'], 'price url');
check($calls[0]['headers']['X-Bifrost-Signature'] === $vector['signature'], 'price signature');
check($calls[0]['body'] === null, 'price has no body');

$calls = [];
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    handler: function (string $method, string $url) use (&$calls, $http): array {
        $calls[] = $method . ' ' . $url;
        if (str_ends_with(parse_url($url, PHP_URL_PATH) ?: '', $http['paths']['fixed_wallets']) && $method === 'POST') {
            return jsonResponse($http['fixed_wallet_created']['status'], $http['fixed_wallet_created']['body']);
        }
        return ok();
    },
);
$payout = samplePayout('order_1');
$bifrost->payouts->create($payout);
$bifrost->payouts->createMany([$payout]);
$bifrost->payouts->list(['page' => 2, 'status' => 'PENDING']);
$bifrost->payouts->retrieve('BIFROST_REF_123456');
$bifrost->payins->create([
    'crypto_currency' => 'USDT',
    'crypto_amount' => '1.00',
    'process_by' => 'crypto_amount',
    'reference_id' => 'invoice_1',
    'network' => 'Ethereum',
    'webhook_url' => 'https://example.com/hook',
]);
$bifrost->payins->list();
$bifrost->payins->retrieve('ref/with slash');
$bifrost->balances->list();
$bifrost->statements->list(['direction' => 'out']);
$bifrost->statements->summary(['date_from' => '2026-01-01']);
$bifrost->summary->retrieve();
$bifrost->summary->tokens();
$bifrost->summary->fiats();
$bifrost->prices->get(['currency1' => 'USDT', 'currency2' => 'USD']);
$bifrost->prices->currencies();
$bifrost->fixedWallets->supported();
$bifrost->fixedWallets->config();
$bifrost->fixedWallets->updateConfig(['webhook_url' => 'https://example.com/wallets']);
$bifrost->fixedWallets->list(['limit' => 10, 'offset' => 0]);
$created = $bifrost->fixedWallets->create(['type' => 'evm', 'customer_info' => 'customer123']);

$base = $http['base_url'];
$expected = [
    'POST ' . $base . $http['paths']['payout'],
    'POST ' . $base . $http['paths']['payout'],
    'GET ' . $base . $http['paths']['list_payout'] . '?page=2&status=PENDING',
    'GET ' . $base . $http['paths']['check_payout'] . '/BIFROST_REF_123456',
    'POST ' . $base . $http['paths']['payin'],
    'GET ' . $base . $http['paths']['list_payin'],
    'GET ' . $base . $http['paths']['check_payin'] . '/ref%2Fwith%20slash',
    'GET ' . $base . $http['paths']['balances'],
    'GET ' . $base . $http['paths']['statements'] . '?direction=out',
    'GET ' . $base . $http['paths']['statements_summary'] . '?date_from=2026-01-01',
    'GET ' . $base . $http['paths']['summary'],
    'GET ' . $base . $http['paths']['tokens'],
    'GET ' . $base . $http['paths']['fiats'],
    'GET ' . $base . $http['paths']['price'] . '?currency1=USDT&currency2=USD',
    'GET ' . $base . $http['paths']['currencies'],
    'GET ' . $base . $http['paths']['fixed_supported'],
    'GET ' . $base . $http['paths']['fixed_config'],
    'PUT ' . $base . $http['paths']['fixed_config'],
    'GET ' . $base . $http['paths']['fixed_wallets'] . '?limit=10&offset=0',
    'POST ' . $base . $http['paths']['fixed_wallets'],
];
check($calls === $expected, 'routes ' . json_encode($calls));
check($created['created'] === true, 'fixed wallet created');

$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    handler: fn () => jsonResponse($http['partial_batch']['status'], $http['partial_batch']['body']),
);
$batch = $bifrost->payouts->createMany([samplePayout('a'), samplePayout('b')]);
check($batch['summary']['failed'] === 1, '207 failed count');
check($batch['results'][1]['index'] === 1, '207 index');

try {
    $bifrost = new Bifrost(
        apiKey: $http['api_key'],
        handler: fn () => jsonResponse($http['processing_exception']['status'], $http['processing_exception']['body']),
    );
    $bifrost->payouts->createMany([samplePayout('a')], 'idem-1');
    check(false, 'processing exception should throw');
} catch (BifrostError $error) {
    check($error->error === 'processing_exception', 'processing exception code');
    check($error->body['summary']['not_processed'] === 18, 'not processed');
}

$sleeps = [];
$calls = 0;
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    sleeper: function (int $ms) use (&$sleeps): void {
        $sleeps[] = $ms;
    },
    handler: function () use (&$calls, $http): array {
        $calls++;
        if ($calls === 1) {
            return jsonResponse($http['rate_limit']['status'], $http['rate_limit']['body']);
        }
        return jsonResponse($http['balances_ok']['status'], $http['balances_ok']['body']);
    },
);
$balances = $bifrost->balances->list();
check($calls === 2, '429 read retries');
check($sleeps[0] === $http['rate_limit']['body']['details']['retry_after_seconds'] * 1000, '429 delay');
check($balances['message'] === 'balances_retrieved_success', '429 eventual success');

$calls = 0;
try {
    $bifrost = new Bifrost(
        apiKey: $http['api_key'],
        handler: function () use (&$calls, $http): array {
            $calls++;
            return jsonResponse($http['rate_limit']['status'], $http['rate_limit']['body']);
        },
    );
    $bifrost->payouts->create(samplePayout('a'));
    check(false, 'write 429 should throw');
} catch (BifrostError $error) {
    check($error->retryAfterSeconds === 24, 'retry after seconds');
}
check($calls === 1, 'write without idempotency is not retried');

$calls = 0;
$sleeps = [];
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    sleeper: function (int $ms) use (&$sleeps): void {
        $sleeps[] = $ms;
    },
    handler: function () use (&$calls, $http): array {
        $calls++;
        if ($calls === 1) {
            return jsonResponse($http['rate_limit']['status'], $http['rate_limit']['body']);
        }
        return jsonResponse(200, ['message' => 'payment_processing_completed']);
    },
);
$bifrost->payouts->create(samplePayout('a'), 'idem-1');
check($calls === 2, 'write with idempotency retries 429');
check($sleeps[0] === 24000, 'write 429 delay');

$keys = [];
$sleeps = [];
$calls = 0;
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    sleeper: function (int $ms) use (&$sleeps): void {
        $sleeps[] = $ms;
    },
    handler: function (string $method, string $url, array $headers) use (&$calls, &$keys, $http): array {
        $calls++;
        $keys[] = $headers['Idempotency-Key'] ?? null;
        if ($calls === 1) {
            return jsonResponse($http['idempotency_in_progress']['status'], $http['idempotency_in_progress']['body']);
        }
        return jsonResponse(200, ['message' => 'payment_processing_completed']);
    },
);
$bifrost->payouts->create(samplePayout('a'), 'idem-1');
check($keys === ['idem-1', 'idem-1'], 'idempotency key is reused');
check($sleeps[0] === $http['default_retry_delay_ms'], '409 delay');

$reads = 0;
$writes = 0;
$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    sleeper: function (int $ms): void {},
    handler: function (string $method) use (&$reads, &$writes, $http): array {
        if ($method === 'GET') {
            $reads++;
            if ($reads === 1) {
                return jsonResponse($http['service_unavailable']['status'], $http['service_unavailable']['body']);
            }
            return ok();
        }
        $writes++;
        return jsonResponse($http['service_unavailable']['status'], $http['service_unavailable']['body']);
    },
);
$bifrost->balances->list();
check($reads === 2, '503 read retries');
try {
    $bifrost->payins->create([
        'crypto_currency' => 'USDT',
        'process_by' => 'crypto_amount',
        'reference_id' => 'invoice_1',
        'network' => 'Ethereum',
        'webhook_url' => 'https://example.com/hook',
        'crypto_amount' => '1.00',
    ]);
    check(false, '503 write should throw');
} catch (BifrostError) {
    check(true, '503 write throws');
}
check($writes === 1, '503 write is not retried');

$calls = 0;
try {
    $bifrost = new Bifrost(
        apiKey: $http['api_key'],
        sleeper: function (int $ms): void {},
        handler: function () use (&$calls, $http): array {
            $calls++;
            return jsonResponse($http['rate_limit']['status'], $http['rate_limit']['body']);
        },
    );
    $bifrost->balances->list();
    check(false, 'exhausted retries should throw');
} catch (BifrostError) {
    check($calls === $http['max_retries'] + 1, 'retry cap');
}

$calls = 0;
try {
    $bifrost = new Bifrost(
        apiKey: $http['api_key'],
        handler: function () use (&$calls): array {
            $calls++;
            return ok();
        },
    );
    $items = [];
    for ($i = 0; $i < $http['max_payouts'] + 1; $i++) {
        $items[] = samplePayout((string) $i);
    }
    $bifrost->payouts->createMany($items);
    check(false, 'oversized batch should throw');
} catch (BifrostError $error) {
    check($error->error === 'max_payouts_exceeded', 'max payouts code');
}
check($calls === 0, 'oversized batch does not call the api');

$bifrost = new Bifrost(
    apiKey: $http['api_key'],
    handler: fn () => jsonResponse($http['fixed_wallet_existing']['status'], $http['fixed_wallet_existing']['body']),
);
$existing = $bifrost->fixedWallets->create(['type' => 'evm', 'customer_info' => 'customer123']);
check($existing['created'] === false, 'existing wallet created false');
check($existing['message'] === 'FixedWallets.already_assigned', 'existing wallet message');

if ($failures > 0) {
    fwrite(STDERR, "{$failures} failed\n");
    exit(1);
}

fwrite(STDOUT, "php sdk ok\n");
