<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class FixedWallets
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @return array<string, mixed>
     */
    public function supported(): array
    {
        return $this->client->request('GET', '/v1/fixed-wallets/supported', ['kind' => 'read'])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function config(): array
    {
        return $this->client->request('GET', '/v1/fixed-wallets/config', ['kind' => 'read'])['body'];
    }

    /**
     * @param array{webhook_url?: string} $body
     * @return array<string, mixed>
     */
    public function updateConfig(array $body): array
    {
        return $this->client->request('PUT', '/v1/fixed-wallets/config', [
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
        return $this->client->request('GET', '/v1/fixed-wallets', [
            'query' => $query,
            'kind' => 'read',
        ])['body'];
    }

    /**
     * @param array{type: string, customer_info?: string} $body
     * @return array<string, mixed>
     */
    public function create(array $body): array
    {
        $result = $this->client->request('POST', '/v1/fixed-wallets', [
            'body' => $body,
            'kind' => 'write',
        ]);
        $payload = is_array($result['body']) ? $result['body'] : [];
        $payload['created'] = $result['status'] === 201;

        return $payload;
    }
}
