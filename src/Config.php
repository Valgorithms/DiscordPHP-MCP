<?php

declare(strict_types=1);

namespace DiscordMcp;

final class Config
{
    /** @param array<string, list<string>> $channels */
    public function __construct(public readonly array $channels, public readonly array $writeChannels = [])
    {
        if ($channels === [] || count($channels) > 20) {
            throw new \InvalidArgumentException('Configure between 1 and 20 guilds.');
        }
        foreach ($channels as $guild => $ids) {
            self::id((string) $guild);
            if (!is_array($ids) || !array_is_list($ids) || count($ids) > 200) {
                throw new \InvalidArgumentException('Each guild needs a channel list of at most 200 IDs.');
            }
            foreach ($ids as $id) {
                if (!is_string($id)) {
                    throw new \InvalidArgumentException('Channel IDs must be strings.');
                }
                self::id($id);
            }
        }
        foreach ($writeChannels as $id) {
            if (!is_string($id) || $this->guildFor($id) === null) {
                throw new \InvalidArgumentException('Writable channels must be a subset of readable channels.');
            }
        }
    }

    public static function load(string $path): array
    {
        if (!is_file($path) || filesize($path) > 65536) {
            throw new \RuntimeException('Connector configuration is missing or oversized.');
        }
        $data = json_decode((string) file_get_contents($path), true, 16, JSON_THROW_ON_ERROR);
        if (!is_array($data) || !is_array($data['channels'] ?? null) || !is_array($data['write_channels'] ?? [])) {
            throw new \RuntimeException('Invalid connector configuration.');
        }
        $config = new self($data['channels'], $data['write_channels'] ?? []);
        $token = getenv('DISCORD_MCP_BOT_TOKEN');
        if (!$token && is_string($data['token_file'] ?? null) && is_file($data['token_file']) && filesize($data['token_file']) < 4096) {
            $token = trim((string) file_get_contents($data['token_file']));
        }
        if (!is_string($token) || trim($token) === '' || preg_match('/\s/', $token)) {
            throw new \RuntimeException('A bot token must be provided through the environment or token file.');
        }

        return [$config, $token];
    }

    public static function id(string $id): void
    {
        if (!preg_match('/^[1-9][0-9]{0,19}$/D', $id)) {
            throw new \InvalidArgumentException('Expected a decimal Discord ID string.');
        }
    }

    public function guildFor(string $channel): ?string
    {
        foreach ($this->channels as $guild => $ids) {
            if (in_array($channel, $ids, true)) {
                return (string) $guild;
            }
        }

        return null;
    }
}
