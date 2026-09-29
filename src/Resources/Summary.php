<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class Summary
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @return array<string, mixed>
     */
    public function retrieve(): array
    {
        return $this->client->request('GET', '/v1/summary', ['kind' => 'read'])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function tokens(): array
    {
        return $this->client->request('GET', '/v1/summary/tokens', ['kind' => 'read'])['body'];
    }

    /**
     * @return array<string, mixed>
     */
    public function fiats(): array
    {
        return $this->client->request('GET', '/v1/summary/fiats', ['kind' => 'read'])['body'];
    }
}
