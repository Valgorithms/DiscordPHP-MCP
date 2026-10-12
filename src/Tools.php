<?php

declare(strict_types=1);

namespace DiscordMcp;

use Discord\Http\Endpoint;
use Mcp\Exception\ToolCallException;
use React\Promise\Timer\TimeoutException;

use function React\Async\await;
use function React\Promise\Timer\timeout;

/** MCP's synchronous callback boundary drives DiscordPHP's asynchronous HTTP promises. */
final class Tools
{
    public function __construct(private readonly Config $config, private readonly Transport $transport, private readonly float $timeout = 30.0)
    {
    }

    public function listGuilds(int $limit = 5, int $offset = 0): array
    {
        if ($limit < 1 || $limit > 5 || $offset < 0 || $offset > 20) {
            throw new ToolCallException('Guild discovery supports 1-5 items and offset 0-20.');
        }
        $items = [];
        foreach (array_slice(array_keys($this->config->channels), $offset, $limit) as $id) {
            $guild = $this->request('get', Endpoint::bind('guilds/:guild_id', (string) $id));
            $items[] = ['id' => (string) $id, 'name' => (string) ($guild['name'] ?? '')];
        }

        return ['guilds' => $items, 'next_offset' => $offset + count($items) < count($this->config->channels) ? $offset + count($items) : null, 'scope' => 'configured guilds only'];
    }

    public function listChannels(string $guildId): array
    {
        Config::id($guildId);
        if (!array_key_exists($guildId, $this->config->channels)) {
            throw new ToolCallException('Guild is outside the configured scope.');
        }
        $channels = $this->request('get', Endpoint::bind('guilds/:guild_id/channels', $guildId));
        $items = [];
        foreach ($channels as $channel) {
            if (in_array($channel['id'] ?? null, $this->config->channels[$guildId], true)) {
                $items[] = ['id' => $channel['id'], 'name' => $channel['name'] ?? '', 'type' => $channel['type'] ?? null];
            }
        }

        return ['channels' => $items, 'scope' => 'explicitly configured channels; threads are not expanded automatically'];
    }

    public function getMessage(string $channelId, string $messageId): array
    {
        $guild = $this->channel($channelId);
        Config::id($messageId);
        $message = $this->request('get', Endpoint::bind('channels/:channel_id/messages/:message_id', $channelId, $messageId));

        return $this->message($message, $guild, $channelId);
    }

    public function history(string $channelId, int $limit = 25, ?string $before = null): array
    {
        $guild = $this->channel($channelId);
        if ($limit < 1 || $limit > 50) {
            throw new ToolCallException('History limit must be between 1 and 50.');
        }
        if ($before !== null) {
            Config::id($before);
        }
        $endpoint = Endpoint::bind('channels/:channel_id/messages', $channelId);
        $endpoint->addQuery('limit', $limit);
        if ($before !== null) {
            $endpoint->addQuery('before', $before);
        }
        $raw = $this->request('get', $endpoint);
        if (!array_is_list($raw) || count($raw) > $limit) {
            throw new ToolCallException('Discord returned an invalid history page.');
        }
        $messages = array_map(fn (array $m): array => $this->message($m, $guild, $channelId), $raw);

        return ['messages' => $messages, 'next_before' => count($raw) === $limit ? end($messages)['id'] : null,
            'scope' => 'one bounded history page; an empty result does not prove message-content access'];
    }

    public function search(string $channelId, string $query, int $maxPages = 2, ?string $before = null): array
    {
        if (trim($query) === '' || mb_strlen($query) > 200 || $maxPages < 1 || $maxPages > 5) {
            throw new ToolCallException('Provide a literal query of 1-200 characters and 1-5 pages.');
        }
        $matches = [];
        $scanned = 0;
        $seen = [];
        for ($page = 0; $page < $maxPages; ++$page) {
            $result = $this->history($channelId, 50, $before);
            foreach ($result['messages'] as $message) {
                if (isset($seen[$message['id']])) {
                    continue;
                }
                $seen[$message['id']] = true;
                ++$scanned;
                if (mb_stripos($message['content'], $query) !== false) {
                    $matches[] = $message;
                    if (count($matches) === 50) {
                        return ['matches' => $matches, 'scanned' => $scanned, 'next_before' => $message['id'], 'history_exhausted' => false,
                            'scope' => 'match limit reached; continue from next_before for older messages'];
                    }
                }
            }
            $next = $result['next_before'];
            if ($next === null) {
                return ['matches' => $matches, 'scanned' => $scanned, 'next_before' => null, 'history_exhausted' => true, 'scope' => 'literal content search of accessible channel history only'];
            }
            if ($next === $before) {
                throw new ToolCallException('History cursor did not advance; search stopped.');
            }
            $before = $next;
        }

        return ['matches' => $matches, 'scanned' => $scanned, 'next_before' => $before, 'history_exhausted' => false,
            'scope' => 'bounded literal search, at most 250 messages and 50 matches; not a complete Discord search'];
    }

    public function send(string $channelId, string $content, string $nonce): array
    {
        $guild = $this->channel($channelId, true);
        $this->content($content);
        if (!preg_match('/^[0-9]{1,25}$/D', $nonce)) {
            throw new ToolCallException('Use a stable decimal nonce of 1-25 digits for this send.');
        }
        $message = $this->request(
            'post',
            Endpoint::bind('channels/:channel_id/messages', $channelId),
            ['content' => $content, 'nonce' => $nonce, 'enforce_nonce' => true, 'allowed_mentions' => ['parse' => [], 'replied_user' => false]]
        );

        return ['status' => 'discord_acknowledged', 'nonce' => $nonce, 'message' => $this->message($message, $guild, $channelId)];
    }

    public function edit(string $channelId, string $messageId, string $content, string $expectedContentSha256): array
    {
        $guild = $this->channel($channelId, true);
        $this->content($content);
        Config::id($messageId);
        $bot = $this->request('get', Endpoint::bind('users/@me'));
        $current = $this->request('get', Endpoint::bind('channels/:channel_id/messages/:message_id', $channelId, $messageId));
        if (($bot['bot'] ?? false) !== true || ($current['author']['id'] ?? null) !== ($bot['id'] ?? null)) {
            throw new ToolCallException('Only this bot identity\'s messages can be edited.');
        }
        if (!hash_equals(hash('sha256', (string) ($current['content'] ?? '')), $expectedContentSha256)) {
            throw new ToolCallException('Message changed since inspection; fetch it again before editing.');
        }
        $message = $this->request(
            'patch',
            Endpoint::bind('channels/:channel_id/messages/:message_id', $channelId, $messageId),
            ['content' => $content, 'allowed_mentions' => ['parse' => [], 'replied_user' => false]]
        );

        return ['status' => 'discord_acknowledged', 'message' => $this->message($message, $guild, $channelId)];
    }

    private function channel(string $id, bool $write = false): string
    {
        Config::id($id);
        $guild = $this->config->guildFor($id);
        if ($guild === null || ($write && !in_array($id, $this->config->writeChannels, true))) {
            throw new ToolCallException('Channel is outside the configured '.($write ? 'write' : 'read').' scope.');
        }
        // Explicitly configured IDs still require a guild channel: never silently open bot DMs.
        $channel = $this->request('get', Endpoint::bind('channels/:channel_id', $id));
        if ((string) ($channel['guild_id'] ?? '') !== $guild || (string) ($channel['id'] ?? '') !== $id) {
            throw new ToolCallException('Channel is not in its configured guild.');
        }

        return $guild;
    }

    private function content(string $content): void
    {
        if (trim($content) === '' || mb_strlen($content) > 2000) {
            throw new ToolCallException('Message content must contain 1-2000 characters.');
        }
    }

    private function request(string $method, Endpoint $endpoint, ?array $payload = null): array
    {
        try {
            return await(timeout($this->transport->request($method, $endpoint, $payload), $this->timeout));
        } catch (TimeoutException) {
            throw new ToolCallException($method === 'get' ? 'Discord read timed out; coverage is unknown.'
                : 'Discord write timed out; delivery is UNKNOWN. Inspect channel history before retrying; never assume failure.');
        } catch (ToolCallException $e) {
            throw $e;
        } catch (\Throwable) {
            throw new ToolCallException($method === 'get' ? 'Discord read failed; coverage is unknown.'
                : 'Discord write failed without a success receipt; delivery is UNKNOWN. Inspect history before retrying.');
        }
    }

    private function message(array $message, string $guild, string $channel): array
    {
        $id = (string) ($message['id'] ?? '');
        Config::id($id);
        if ((string) ($message['channel_id'] ?? '') !== $channel) {
            throw new ToolCallException('Discord returned a message from an unexpected channel.');
        }
        $content = (string) ($message['content'] ?? '');

        return ['id' => $id, 'channel_id' => $channel, 'guild_id' => $guild, 'author_id' => (string) ($message['author']['id'] ?? ''),
            'author_name' => (string) ($message['author']['username'] ?? ''), 'author_bot' => (bool) ($message['author']['bot'] ?? false),
            'content' => mb_substr($content, 0, 4000), 'content_sha256' => hash('sha256', $content),
            'timestamp' => $message['timestamp'] ?? null, 'edited_timestamp' => $message['edited_timestamp'] ?? null,
            'url' => 'https://discord.com/channels/'.$guild.'/'.$channel.'/'.$id, 'untrusted_content' => true];
    }
}
