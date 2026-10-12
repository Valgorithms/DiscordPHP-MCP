<?php

declare(strict_types=1);

require __DIR__.'/vendor/autoload.php';

use Discord\Http\Endpoint;
use Discord\Http\Http;
use Discord\Http\Drivers\React;
use DiscordMcp\Config;
use DiscordMcp\DiscordTransport;
use DiscordMcp\ServerFactory;
use DiscordMcp\Tools;
use Mcp\Server\Transport\StdioTransport;
use Psr\Log\NullLogger;
use React\EventLoop\Loop;

use function React\Async\await;
use function React\Promise\Timer\timeout;

// Protocol output is stdout; never print configuration, upstream bodies, or tokens.
ini_set('display_errors', 'stderr');
try {
    [$config, $token] = Config::load(getenv('DISCORD_MCP_CONFIG') ?: __DIR__.'/config.local.json');
    $transport = new DiscordTransport(new Http('Bot '.$token, Loop::get(), new NullLogger(), new React(Loop::get())));
    $identity = await(timeout($transport->request('get', Endpoint::bind('users/@me')), 30));
    if (($identity['bot'] ?? false) !== true) {
        throw new RuntimeException('Connector requires a Discord bot identity.');
    }
    exit(ServerFactory::create($config, new Tools($config, $transport))->run(new StdioTransport()));
} catch (Throwable) {
    fwrite(STDERR, "Discord MCP startup failed. Check private configuration, bot identity, network and permissions.\n");
    exit(1);
}
