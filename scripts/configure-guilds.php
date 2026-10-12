<?php

declare(strict_types=1);

require dirname(__DIR__).'/vendor/autoload.php';

use Discord\Http\Endpoint;
use Discord\Http\Http;
use Discord\Http\Drivers\React;
use DiscordMcp\Config;
use DiscordMcp\DiscordTransport;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

use function React\Async\await;
use function React\Promise\Timer\timeout;

// Explicit setup only: snapshot channels from user-authorized guilds, never publish.
try {
    $path = getenv('DISCORD_MCP_CONFIG') ?: dirname(__DIR__).'/config.local.json';
    $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
    $guilds = array_slice($argv, 1);
    if ($guilds === [] || count($guilds) > 20) {
        throw new RuntimeException('Supply 1-20 explicitly authorized guild IDs.');
    }
    foreach ($guilds as $guild) {
        Config::id($guild);
    }
    // Validate credentials using a temporary deny-message scope, without changing the file.
    $temp = tempnam(sys_get_temp_dir(), 'mcp-setup-');
    try {
        file_put_contents($temp, json_encode(array_replace($data, ['channels' => [$guilds[0] => []], 'write_channels' => []]), JSON_THROW_ON_ERROR));
        [, $token] = Config::load($temp);
    } finally {
        unlink($temp);
    }
    $transport = new DiscordTransport(new Http('Bot '.$token, Loop::get(), new NullLogger(), new React(Loop::get())));
    $identity = await(timeout($transport->request('get', Endpoint::bind('users/@me')), 30));
    if (($identity['bot'] ?? false) !== true) {
        throw new RuntimeException('Bot identity required.');
    }
    $channels = [];
    foreach ($guilds as $guild) {
        $rows = await(timeout($transport->request('get', Endpoint::bind('guilds/:guild_id/channels', $guild)), 30));
        $channels[$guild] = [];
        foreach ($rows as $row) {
            if (is_array($row) && ($row['type'] ?? null) !== 4 && is_string($row['id'] ?? null)) {
                Config::id($row['id']);
                $channels[$guild][] = $row['id'];
            }
        }
    }
    new Config($channels);
    $data['channels'] = $channels;
    $data['write_channels'] = [];
    if (file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n", LOCK_EX) === false) {
        throw new RuntimeException('Cannot save private configuration.');
    }
    foreach ($channels as $guild => $ids) {
        fwrite(STDOUT, $guild.': '.count($ids)." readable channel IDs configured; writes disabled.\n");
    }
    exit(0);
} catch (Throwable) {
    fwrite(STDERR, "Setup failed; no success claimed. Check bot identity, guild access and private configuration.\n");
    exit(1);
}
