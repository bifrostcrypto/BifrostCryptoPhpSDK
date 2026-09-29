<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk;

use BifrostCrypto\Sdk\Resources\Balances;
use BifrostCrypto\Sdk\Resources\FixedWallets;
use BifrostCrypto\Sdk\Resources\Payins;
use BifrostCrypto\Sdk\Resources\Payouts;
use BifrostCrypto\Sdk\Resources\Prices;
use BifrostCrypto\Sdk\Resources\Statements;
use BifrostCrypto\Sdk\Resources\Summary;

class Bifrost
{
    public const BASE_URL = 'https://bridge.bifrostcrypto.com';
    public const MAX_RETRIES = 2;
    public const DEFAULT_RETRY_DELAY_MS = 1000;
    public const REQUEST_TIMEOUT_SECONDS = 30;

    public readonly Payouts $payouts;
    public readonly Payins $payins;
    public readonly Balances $balances;
    public readonly Statements $statements;
    public readonly Summary $summary;
    public readonly Prices $prices;
    public readonly FixedWallets $fixedWallets;

    private readonly string $baseUrl;

    /**
     * @param null|callable(string, string, array<string, string>, ?string): array{status: int, headers: array<string, string>, body: string} $handler
     * @param null|callable(): int $clock
     * @param null|callable(): string $nonce
     * @param null|callable(int): void $sleeper
     */
    public function __construct(
        private readonly string $apiKey,
        private readonly ?string $signingSecret = null,
        string $baseUrl = self::BASE_URL,
        private readonly mixed $handler = null,
        private readonly mixed $clock = null,
        private readonly mixed $nonce = null,
        private readonly mixed $sleeper = null,
        private readonly int $maxRetries = self::MAX_RETRIES,
    ) {
        if ($this->apiKey === '') {
            throw new BifrostError(0, 'missing_api_key', 'apiKey is required');
        }
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->payouts = new Payouts($this);
        $this->payins = new Payins($this);
        $this->balances = new Balances($this);
        $this->statements = new Statements($this);
        $this->summary = new Summary($this);
        $this->prices = new Prices($this);
        $this->fixedWallets = new FixedWallets($this);
    }

    /**
     * @param array{query?: array<string, mixed>, body?: mixed, idempotencyKey?: ?string, kind?: string} $options
     * @return array{status: int, headers: array<string, string>, body: mixed}
     */
    public function request(string $method, string $path, array $options = []): array
    {
        $kind = $options['kind'] ?? 'read';
        $query = self::encodeQuery($options['query'] ?? null);
        $body = array_key_exists('body', $options) ? self::encodeBody($options['body']) : null;
        $idempotencyKey = $options['idempotencyKey'] ?? null;
        $url = $this->baseUrl . $path . ($query !== '' ? '?' . $query : '');
        $attempt = 0;

        while (true) {
            $headers = ['X-Bifrost-Invoke' => $this->apiKey];
            if ($body !== null) {
                $headers['Content-Type'] = 'application/json';
            }
            if (is_string($idempotencyKey) && $idempotencyKey !== '') {
                $headers['Idempotency-Key'] = $idempotencyKey;
            }
            if (is_string($this->signingSecret) && $this->signingSecret !== '') {
                $timestamp = (string) ($this->clock !== null ? ($this->clock)() : time());
                $nonce = $this->nonce !== null ? (string) ($this->nonce)() : bin2hex(random_bytes(16));
                $headers['X-Bifrost-Timestamp'] = $timestamp;
                $headers['X-Bifrost-Nonce'] = $nonce;
                $headers['X-Bifrost-Signature'] = Signer::sign(
                    $this->signingSecret,
                    $method,
                    $path,
                    $query,
                    $timestamp,
                    $nonce,
                    $body ?? '',
                );
            }

            try {
                $response = $this->send($method, $url, $headers, $body);
            } catch (BifrostError $error) {
                throw $error;
            } catch (\Throwable $cause) {
                throw new BifrostError(0, 'network_error', $cause->getMessage());
            }

            $responseHeaders = self::lowercaseHeaders($response['headers']);
            try {
                $parsed = self::parseJson($response['body']);
            } catch (\JsonException) {
                throw new BifrostError($response['status'], 'invalid_json', 'Response body is not JSON', null, $response['body']);
            }

            if ($response['status'] >= 200 && $response['status'] < 300) {
                return [
                    'status' => $response['status'],
                    'headers' => $responseHeaders,
                    'body' => $parsed,
                ];
            }

            $error = self::toError($response['status'], $parsed, $responseHeaders);
            $idempotency = is_string($idempotencyKey) && $idempotencyKey !== '' ? $idempotencyKey : null;
            if (!self::shouldRetry($response['status'], $error->error, $attempt, $this->maxRetries, $kind, $idempotency)) {
                throw $error;
            }

            $delay = $response['status'] === 429 && $error->retryAfterSeconds !== null
                ? $error->retryAfterSeconds * 1000
                : self::DEFAULT_RETRY_DELAY_MS;
            if ($this->sleeper !== null) {
                ($this->sleeper)($delay);
            } else {
                usleep($delay * 1000);
            }
            $attempt++;
        }
    }

    /**
     * @param array<string, mixed>|null $query
     */
    public static function encodeQuery(?array $query): string
    {
        if ($query === null) {
            return '';
        }
        $parts = [];
        foreach ($query as $key => $value) {
            if ($value === null) {
                continue;
            }
            $parts[] = rawurlencode((string) $key) . '=' . rawurlencode(self::queryText($value));
        }

        return implode('&', $parts);
    }

    /**
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $url, array $headers, ?string $body): array
    {
        if ($this->handler !== null) {
            $response = ($this->handler)($method, $url, $headers, $body);
            $response['headers'] = self::lowercaseHeaders($response['headers'] ?? []);

            return $response;
        }

        if (!function_exists('curl_init')) {
            throw new BifrostError(0, 'network_error', 'ext-curl is required');
        }

        $handle = curl_init($url);
        if ($handle === false) {
            throw new BifrostError(0, 'network_error', 'Failed to start the request');
        }

        $lines = [];
        foreach ($headers as $key => $value) {
            $lines[] = $key . ': ' . $value;
        }

        curl_setopt_array($handle, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $lines,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => self::REQUEST_TIMEOUT_SECONDS,
        ]);
        if ($body !== null) {
            curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        }

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            curl_close($handle);
            throw new BifrostError(0, 'network_error', $message !== '' ? $message : 'Network request failed');
        }

        $status = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        curl_close($handle);

        return [
            'status' => $status,
            'headers' => self::parseRawHeaders(substr($raw, 0, $headerSize)),
            'body' => substr($raw, $headerSize),
        ];
    }

    private static function encodeBody(mixed $body): ?string
    {
        if ($body === null) {
            return null;
        }

        return json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private static function parseJson(string $raw): mixed
    {
        if ($raw === '') {
            return null;
        }

        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param array<string, string> $headers
     */
    private static function toError(int $status, mixed $parsed, array $headers): BifrostError
    {
        $record = is_array($parsed) ? $parsed : [];
        $code = is_string($record['error'] ?? null) ? $record['error'] : 'http_error';
        $message = is_string($record['message'] ?? null) ? $record['message'] : $code;
        $retry = $status === 429 ? self::retryAfterSeconds($record, $headers) : null;

        return new BifrostError($status, $code, $message, $record['details'] ?? null, $parsed, $retry);
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, string> $headers
     */
    private static function retryAfterSeconds(array $record, array $headers): ?int
    {
        $details = $record['details'] ?? null;
        if (is_array($details) && isset($details['retry_after_seconds']) && is_int($details['retry_after_seconds'])) {
            return $details['retry_after_seconds'];
        }
        $header = $headers['retry-after'] ?? null;
        if (is_string($header) && ctype_digit($header)) {
            return (int) $header;
        }

        return null;
    }

    private static function shouldRetry(
        int $status,
        string $errorCode,
        int $attempt,
        int $maxRetries,
        string $kind,
        ?string $idempotencyKey,
    ): bool {
        if ($attempt >= $maxRetries) {
            return false;
        }
        if ($status === 429) {
            return $kind === 'read' || $idempotencyKey !== null;
        }
        if ($status === 409 && $errorCode === 'idempotency_key_in_progress' && $idempotencyKey !== null) {
            return true;
        }
        if ($status === 503 && $kind === 'read') {
            return true;
        }

        return false;
    }

    private static function queryText(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    /**
     * @param array<string, string> $headers
     * @return array<string, string>
     */
    private static function lowercaseHeaders(array $headers): array
    {
        $out = [];
        foreach ($headers as $key => $value) {
            $out[strtolower((string) $key)] = (string) $value;
        }

        return $out;
    }

    /**
     * @return array<string, string>
     */
    private static function parseRawHeaders(string $raw): array
    {
        $out = [];
        foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }
            $out[strtolower(trim($parts[0]))] = trim($parts[1]);
        }

        return $out;
    }
}
