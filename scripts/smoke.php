<?php

declare(strict_types=1);

// Real SDK stdio process, entirely synthetic Discord transport; never loads bot credentials.
foreach ([false, true] as $writable) {
    $process = proc_open(
        [PHP_BINARY, __DIR__.'/../tests/fixture-server.php', $writable ? 'writable' : 'readonly'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes
    );
    if (!is_resource($process)) {
        throw new RuntimeException('Fixture process could not start.');
    }
    stream_set_timeout($pipes[1], 10);
    $request = static function (array $message) use ($pipes): array {
        fwrite($pipes[0], json_encode($message, JSON_THROW_ON_ERROR)."\n");
        fflush($pipes[0]);
        $line = fgets($pipes[1]);
        if ($line === false) {
            throw new RuntimeException('Offline MCP response missing.');
        }
        return json_decode($line, true, 32, JSON_THROW_ON_ERROR);
    };
    try {
        $initialized = $request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => [
            'protocolVersion' => '2025-06-18', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'offline-smoke', 'version' => '1'],
        ]]);
        if (($initialized['result']['serverInfo']['name'] ?? '') !== 'discordphp-mcp') {
            throw new RuntimeException('SDK initialization did not return server identity.');
        }
        fwrite($pipes[0], json_encode(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'])."\n");
        $listed = $request(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'tools/list']);
        $names = array_column($listed['result']['tools'] ?? [], 'name');
        if (count($names) !== ($writable ? 7 : 5) || in_array('discord_send', $names, true) !== $writable) {
            throw new RuntimeException('Wrong read/write tool registration.');
        }
        $called = $request(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/call', 'params' => [
            'name' => 'discord_listGuilds', 'arguments' => (object) [],
        ]]);
        if (isset($called['error']) || ($called['result']['isError'] ?? false)
            || !str_contains(json_encode($called), 'Offline fixture')) {
            throw new RuntimeException('Offline MCP tool invocation failed.');
        }
        $denied = $request(['jsonrpc' => '2.0', 'id' => 4, 'method' => 'tools/call', 'params' => [
            'name' => 'discord_getMessage', 'arguments' => ['channelId' => '999', 'messageId' => '333'],
        ]]);
        if (($denied['result']['isError'] ?? false) !== true || !str_contains(json_encode($denied), 'outside the configured')) {
            throw new RuntimeException('Scope denial was not reported as an MCP tool error.');
        }
    } finally {
        fclose($pipes[0]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_terminate($process);
        proc_close($process);
    }
}
echo "Offline MCP initialize/list/call and read/write registration passed.\n";
