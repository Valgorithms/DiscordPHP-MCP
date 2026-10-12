<?php

declare(strict_types=1);

namespace Tests;

use DiscordMcp\Config;
use DiscordMcp\Tools;
use Mcp\Exception\ToolCallException;
use PHPUnit\Framework\TestCase;
use React\Promise\Deferred;

final class ToolsTest extends TestCase
{
    public function testScopeDeniesBeforeTransportAndRejectsWrongGuildOrDm(): void
    {
        $transport = new FakeTransport(static fn (): array => ['id' => '222']);
        $tools = new Tools(new Config(['111' => ['222']]), $transport);
        try {
            $tools->getMessage('999', '333');
            self::fail('Out-of-scope channel accepted');
        } catch (ToolCallException) {
            self::assertSame([], $transport->calls);
        }
        $this->expectException(ToolCallException::class);
        $tools->getMessage('222', '333');
    }

    public function testHistoryHasSafeBoundedResultsAndExactCursor(): void
    {
        $transport = new FakeTransport(static fn ($method, $path): array => $path === 'channels/222'
            ? ['id' => '222', 'guild_id' => '111'] : [FakeTransport::message()]);
        $result = (new Tools(new Config(['111' => ['222']]), $transport))->history('222', 1, '555');
        self::assertSame('333', $result['next_before']);
        self::assertSame('channels/222/messages?limit=1&before=555', $transport->calls[1][1]);
        self::assertSame('https://discord.com/channels/111/222/333', $result['messages'][0]['url']);
        self::assertTrue($result['messages'][0]['untrusted_content']);
        self::assertSame(hash('sha256', 'hello'), $result['messages'][0]['content_sha256']);
    }

    public function testSearchStopsAtMatchLimitWithoutSkippingOlderMessages(): void
    {
        $transport = new FakeTransport(static function ($method, $path): array {
            if ($path === 'channels/222') {
                return ['id' => '222', 'guild_id' => '111'];
            }
            return array_map(static fn ($i): array => FakeTransport::message((string) (1000 - $i), 'HELLO'), range(1, 50));
        });
        $result = (new Tools(new Config(['111' => ['222']]), $transport))->search('222', 'hello', 5);
        self::assertCount(50, $result['matches']);
        self::assertSame(50, $result['scanned']);
        self::assertSame('950', $result['next_before']);
        self::assertFalse($result['history_exhausted']);
        self::assertCount(2, $transport->calls);
    }

    public function testSearchHasStrictPageLimitAndReportsIncompleteScan(): void
    {
        $transport = new FakeTransport(static function ($method, $path): array {
            if ($path === 'channels/222') {
                return ['id' => '222', 'guild_id' => '111'];
            }
            parse_str((string) parse_url($path, PHP_URL_QUERY), $query);
            $base = (int) ($query['before'] ?? 1000);
            return array_map(static fn ($i): array => FakeTransport::message((string) ($base - $i), 'other'), range(1, 50));
        });
        $result = (new Tools(new Config(['111' => ['222']]), $transport))->search('222', 'none', 5);
        self::assertSame(250, $result['scanned']);
        self::assertSame([], $result['matches']);
        self::assertFalse($result['history_exhausted']);
        self::assertSame('750', $result['next_before']);
    }

    public function testSendIsDenyByDefaultAndSuppressesMentionsWithReceipt(): void
    {
        $transport = new FakeTransport(static fn ($method): array => $method === 'get'
            ? ['id' => '222', 'guild_id' => '111'] : FakeTransport::message());
        try {
            (new Tools(new Config(['111' => ['222']]), $transport))->send('222', 'hello', '123');
            self::fail('Read-only policy allowed sending');
        } catch (ToolCallException) {
            self::assertSame([], $transport->calls);
        }
        $result = (new Tools(new Config(['111' => ['222']], ['222']), $transport))->send('222', 'hello', '123');
        self::assertSame('discord_acknowledged', $result['status']);
        self::assertSame('333', $result['message']['id']);
        self::assertSame(['parse' => [], 'replied_user' => false], $transport->calls[1][2]['allowed_mentions']);
        self::assertTrue($transport->calls[1][2]['enforce_nonce']);
        self::assertSame('123', $transport->calls[1][2]['nonce']);
    }

    public function testEditRejectsOtherAuthorAndStaleContentBeforeWriting(): void
    {
        foreach (['other', 'stale'] as $case) {
            $transport = new FakeTransport(static function ($method, $path) use ($case): array {
                return match ($path) {
                    'channels/222' => ['id' => '222', 'guild_id' => '111'],
                    'users/@me' => ['id' => $case === 'other' ? '555' : '444', 'bot' => true],
                    default => FakeTransport::message(),
                };
            });
            try {
                (new Tools(new Config(['111' => ['222']], ['222']), $transport))->edit('222', '333', 'new', 'wrong');
                self::fail('Unsafe edit allowed');
            } catch (ToolCallException) {
                self::assertSame(['get', 'get', 'get'], array_column($transport->calls, 0));
            }
        }
    }

    public function testEditOwnMessageReturnsDiscordReceipt(): void
    {
        $transport = new FakeTransport(static fn ($method, $path): array => match ($path) {
            'channels/222' => ['id' => '222', 'guild_id' => '111'],
            'users/@me' => ['id' => '444', 'bot' => true],
            default => FakeTransport::message('333', $method === 'patch' ? 'new' : 'hello'),
        });
        $result = (new Tools(new Config(['111' => ['222']], ['222']), $transport))->edit('222', '333', 'new', hash('sha256', 'hello'));
        self::assertSame('new', $result['message']['content']);
        self::assertSame('patch', $transport->calls[3][0]);
        self::assertSame([], $transport->calls[3][2]['allowed_mentions']['parse']);
    }

    public function testWriteTimeoutReportsUncertainDeliveryAndDoesNotRetry(): void
    {
        $transport = new FakeTransport(static fn ($method): array|\React\Promise\PromiseInterface => $method === 'get'
            ? ['id' => '222', 'guild_id' => '111'] : (new Deferred())->promise());
        try {
            (new Tools(new Config(['111' => ['222']], ['222']), $transport, 0.001))->send('222', 'hello', '123');
            self::fail('Missing receipt was accepted');
        } catch (ToolCallException $e) {
            self::assertStringContainsString('delivery is UNKNOWN', $e->getMessage());
            self::assertCount(2, $transport->calls);
        }
    }

    public function testWritePolicyMustBeReadableAndIdsRemainStrings(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Config(['111' => ['222']], ['999']);
    }
}
