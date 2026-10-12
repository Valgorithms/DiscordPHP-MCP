<?php

declare(strict_types=1);

namespace Tests;

use Discord\Http\Endpoint;
use DiscordMcp\Transport;
use React\Promise\PromiseInterface;

use function React\Promise\resolve;

final class FakeTransport implements Transport
{
    public array $calls = [];

    public function __construct(private readonly \Closure $handler)
    {
    }

    public function request(string $method, Endpoint $endpoint, ?array $payload = null): PromiseInterface
    {
        $this->calls[] = [$method, (string) $endpoint, $payload];
        $result = ($this->handler)($method, (string) $endpoint, $payload);

        return $result instanceof PromiseInterface ? $result : resolve($result);
    }

    public static function message(string $id = '333', string $content = 'hello'): array
    {
        return ['id' => $id, 'channel_id' => '222', 'author' => ['id' => '444', 'username' => 'fixture', 'bot' => true], 'content' => $content,
            'timestamp' => '2026-10-12T12:00:00Z'];
    }
}
