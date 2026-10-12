<?php

declare(strict_types=1);

namespace DiscordMcp;

use Discord\Http\Endpoint;
use React\Promise\PromiseInterface;

interface Transport
{
    public function request(string $method, Endpoint $endpoint, ?array $payload = null): PromiseInterface;
}
