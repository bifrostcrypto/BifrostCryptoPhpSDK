<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class Prices
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @param array{currency1: string, currency2: string} $query
     * @return array<string, mixed>
     */
    public function get(array $query): array
    {
        return $this->client->request('GET', '/v1/get-price', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function currencies(): array
    {
        return $this->client->request('GET', '/v1/get-currencies', ['kind' => 'read'])['body'];
    }
}
