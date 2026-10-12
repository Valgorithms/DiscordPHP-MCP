<?php

declare(strict_types=1);

namespace Tests;

use Discord\Http\DriverInterface;
use Discord\Http\Endpoint;
use Discord\Http\Http;
use Discord\Http\Request;
use DiscordMcp\DiscordTransport;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;
use React\Http\Message\Response;
use React\Promise\PromiseInterface;

use function React\Async\await;
use function React\Promise\resolve;
use function React\Promise\Timer\timeout;

final class DiscordTransportTest extends TestCase
{
    public function testBotAuthenticationAndRateLimitRetryUseDiscordPhpTransport(): void
    {
        $driver = new class () implements DriverInterface {
            public array $requests = [];

            public function runRequest(Request $request): PromiseInterface
            {
                $this->requests[] = $request;
                return resolve(count($this->requests) === 1
                    ? new Response(429, ['Content-Type' => 'application/json'], '{"global":false,"retry_after":0.001}')
                    : new Response(200, ['Content-Type' => 'application/json'], '{"id":"222","guild_id":"111"}'));
            }
        };
        $transport = new DiscordTransport(new Http('Bot synthetic-only', Loop::get(), new NullLogger(), $driver));
        $result = await(timeout($transport->request('get', Endpoint::bind('channels/:channel_id', '222')), 1));
        self::assertSame('222', $result['id']);
        self::assertCount(2, $driver->requests);
        self::assertSame('Bot synthetic-only', $driver->requests[0]->getHeaders()['Authorization']);
        self::assertStringEndsWith('/channels/222', $driver->requests[0]->getUrl());
    }

    public function testDiscordErrorBodyNeverReachesMcpCaller(): void
    {
        $driver = new class () implements DriverInterface {
            public function runRequest(Request $request): PromiseInterface
            {
                return resolve(new Response(403, ['Content-Type' => 'application/json'], '{"code":50001,"message":"secret-body-sentinel"}'));
            }
        };
        $transport = new DiscordTransport(new Http('Bot synthetic-only', Loop::get(), new NullLogger(), $driver));
        try {
            await($transport->request('get', Endpoint::bind('channels/:channel_id', '222')));
            self::fail('Error body accepted as data');
        } catch (ToolCallException $e) {
            self::assertStringNotContainsString('secret-body-sentinel', $e->getMessage());
            self::assertStringNotContainsString('synthetic-only', $e->getMessage());
        }
    }
}
