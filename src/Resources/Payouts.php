<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;
use BifrostCrypto\Sdk\BifrostError;

class Payouts
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body, ?string $idempotencyKey = null): array
    {
        return $this->client->request('POST', '/v1/payout', [
            'body' => $body,
            'idempotencyKey' => $idempotencyKey,
            'kind' => 'write',
        ])['body'];
    }

    /**
     * @param list<array<string, mixed>> $items
     * @return array<string, mixed>
     */
    public function createMany(array $items, ?string $idempotencyKey = null): array
    {
        if (count($items) < 1 || count($items) > 50) {
            throw new BifrostError(400, 'max_payouts_exceeded', 'A payout batch must contain between 1 and 50 items');
        }

        return $this->client->request('POST', '/v1/payout', [
            'body' => array_values($items),
            'idempotencyKey' => $idempotencyKey,
            'kind' => 'write',
        ])['body'];
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function list(?array $query = null): array
    {
        return $this->client->request('GET', '/v1/list-payout', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $internalReference): array
    {
        return $this->client->request('GET', '/v1/check-payout/' . rawurlencode($internalReference), [
            'kind' => 'read',
        ])['body'];
    }
}
