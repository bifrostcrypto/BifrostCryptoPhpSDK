<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class Payins
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     */
    public function create(array $body): array
    {
        return $this->client->request('POST', '/v1/payin', [
            'body' => $body,
            'kind' => 'write',
        ])['body'];
    }

    /**
     * @param array<string, mixed>|null $query
     * @return array<string, mixed>
     */
    public function list(?array $query = null): array
    {
        return $this->client->request('GET', '/v1/list-payin', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function retrieve(string $internalReference): array
    {
        return $this->client->request('GET', '/v1/check-payin/' . rawurlencode($internalReference), [
            'kind' => 'read',
        ])['body'];
    }
}
