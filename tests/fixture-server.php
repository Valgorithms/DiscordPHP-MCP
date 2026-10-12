<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

use DiscordMcp\Config;
use DiscordMcp\ServerFactory;
use DiscordMcp\Tools;
use Mcp\Server\Transport\StdioTransport;
use Tests\FakeTransport;

$transport = new FakeTransport(static function ($method, $path): \React\Promise\PromiseInterface {
    $deferred = new \React\Promise\Deferred();
    \React\EventLoop\Loop::addTimer(0.002, static function () use ($deferred, $path): void {
        $deferred->resolve(match ($path) {
            'guilds/111' => ['id' => '111', 'name' => 'Offline fixture'],
            'channels/222' => ['id' => '222', 'guild_id' => '111'],
            default => [FakeTransport::message()],
        });
    });
    return $deferred->promise();
});
$config = new Config(['111' => ['222']], ($argv[1] ?? '') === 'writable' ? ['222'] : []);
exit(ServerFactory::create($config, new Tools($config, $transport))->run(new StdioTransport()));
