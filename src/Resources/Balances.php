<?php

declare(strict_types=1);

namespace BifrostCrypto\Sdk\Resources;

use BifrostCrypto\Sdk\Bifrost;

class Balances
{
    public function __construct(private readonly Bifrost $client) {}

    /**
     * @return array<string, mixed>
     */
    public function list(): array
    {
        return $this->client->request('GET', '/v1/balances', ['kind' => 'read'])['body'];
    }
}
