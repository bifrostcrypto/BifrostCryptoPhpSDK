<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class Statements
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function list(?array $query = null): array
    {
        return $this->client->request('GET', '/v1/statements', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function summary(?array $query = null): array
    {
        return $this->client->request('GET', '/v1/statements/summary', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }
}
