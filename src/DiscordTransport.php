<?php

declare(strict_types=1);

namespace DiscordMcp;

use Discord\Http\Endpoint;
use Discord\Http\Http;
use Mcp\Exception\ToolCallException;
use React\Promise\PromiseInterface;

/** DiscordPHP owns HTTP authentication, per-route buckets and Discord retry-after handling. */
final class DiscordTransport implements Transport
{
    public function __construct(private readonly Http $http)
    {
    }

    public function request(string $method, Endpoint $endpoint, ?array $payload = null): PromiseInterface
    {
        if (!in_array($method, ['get', 'post', 'patch'], true)) {
            throw new \InvalidArgumentException('Unsupported transport method.');
        }

        return $this->http->$method($endpoint, $payload)->then(
            static fn ($value): array => json_decode(json_encode($value, JSON_THROW_ON_ERROR), true, 64, JSON_THROW_ON_ERROR),
            static function (\Throwable $error): never {
                // Upstream bodies may contain message text or credentials: never return them to MCP.
                $code = (int) $error->getCode();
                throw new ToolCallException(in_array($code, [401, 403, 404, 429], true)
                    ? 'Discord refused the request (HTTP '.$code.'); check bot access and permissions.'
                    : 'Discord request failed; no success receipt was received.');
            },
        );
    }
}
