<?php

declare(strict_types=1);

namespace Tests;

use DiscordMcp\Config;
use PHPUnit\Framework\TestCase;

final class ConfigTest extends TestCase
{
    public function testPrivateFileConfigurationAndDenyByDefaultWrites(): void
    {
        $configFile = tempnam(sys_get_temp_dir(), 'mcp-config-');
        $tokenFile = tempnam(sys_get_temp_dir(), 'mcp-token-');
        $previous = getenv('DISCORD_MCP_BOT_TOKEN');
        putenv('DISCORD_MCP_BOT_TOKEN');
        try {
            file_put_contents($tokenFile, "synthetic-token-only\n");
            file_put_contents($configFile, json_encode(['token_file' => $tokenFile, 'channels' => ['111' => ['222']]]));
            [$config, $token] = Config::load($configFile);
            self::assertSame('synthetic-token-only', $token);
            self::assertSame('111', $config->guildFor('222'));
            self::assertNull($config->guildFor('999'));
            self::assertSame([], $config->writeChannels);
        } finally {
            unlink($configFile);
            unlink($tokenFile);
            putenv($previous === false ? 'DISCORD_MCP_BOT_TOKEN' : 'DISCORD_MCP_BOT_TOKEN='.$previous);
        }
    }

    public function testMissingConfigurationHasNoPathOrCredentialDetails(): void
    {
        try {
            Config::load(sys_get_temp_dir().'/nonexistent-secret-sentinel/config.json');
            self::fail('Missing config accepted');
        } catch (\RuntimeException $e) {
            self::assertStringNotContainsString('secret-sentinel', $e->getMessage());
        }
    }
}
