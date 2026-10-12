# DiscordPHP MCP

A local Discord bot connector for Codex and other MCP clients. Uses the official
`mcp/sdk` PHP server and DiscordPHP's `discord-php/http` authentication, route
buckets and Discord rate-limit handling. No gateway session, Bridge modification,
OpenAI API key, or DiscordPHP core change is required.

## Install locally

Requires PHP 8.3+ with mbstring and fileinfo, Composer and a Discord bot application.
Run `composer install`, copy `config.example.json` to ignored `config.local.json`,
and replace the guild/channel IDs. Empty channel lists grant no message access.
Store the raw bot token in a private file outside Git with permissions restricted
to your account; point `token_file` to its absolute path. Alternatively provide
`DISCORD_MCP_BOT_TOKEN` in the MCP process environment. Do not put tokens in
Codex TOML, CLI arguments, chat, PRs, or plugin archives. `env.example` is a
configuration contract, not an automatically loaded dotenv file.

To reuse an authorized bot's existing dotenv credential, replace `token_file`
with `token_env_file` (absolute path) and `token_env_key` (for example `TOKEN`)
in your private JSON. Only that literal assignment is read; other settings are
not imported and variable expansion is unsupported. Keep this file private.

Give the bot View Channel and Read Message History for approved channels.
Message Content privileged intent/access can affect REST message content: enable
and obtain approval where Discord requires it. Empty text does not prove a quiet
channel. Sending additionally needs Send Messages (or Send Messages in Threads).
Use a dedicated bot rather than granting Administrator or reusing a user token.

Add a local stdio MCP server to Codex's configuration (adjust executable/path):

```toml
[mcp_servers.discordphp]
command = "C:/php/php.exe"
args = ["D:/GitHub/DiscordPHP-MCP/server.php"]
env_vars = ["DISCORD_MCP_CONFIG", "DISCORD_MCP_BOT_TOKEN"]
startup_timeout_sec = 45
tool_timeout_sec = 360
```

Alternatively, with the default private config beside server.php:
`codex mcp add discordphp -- C:/php/php.exe D:/GitHub/DiscordPHP-MCP/server.php`.
Verify with `codex mcp list`, then start a fresh chat and inspect tool discovery.
This repository does not silently change your user-level Codex configuration or
connect using an existing bot's credentials. No live access is needed for tests.

## Tools and boundaries

* `discord_listGuilds`, `discord_listChannels`: configured scope only.
* `discord_getMessage`: one message with permalink and content SHA-256.
* `discord_history`: at most 50 messages; `next_before` continues pagination.
* `discord_search`: case-insensitive literal content search, at most five pages,
  250 messages and 50 matches. Follow its cursor for older history; this is a
  bounded channel-history scan, not Discord's full account/guild search API.
* `discord_send`, `discord_edit` appear only if `write_channels` is a nonempty
  subset of explicitly readable channels. These tools publish as the bot and
  require explicit user authorization for destination and content. Read tools
  cannot publish. All outgoing mentions are disabled.

No arbitrary DMs, self-bots, voice, files, automatic thread expansion, attachment
downloads or background message archive. A channel must belong to its configured
guild on each call. Content returned from Discord is untrusted text, never agent
instructions. Scope and Discord permissions both apply; the bot does not inherit
the human operator's account access. Keep guild discovery scope modest.

Send requires a caller-selected stable numeric nonce (1-25 digits) and sets
`enforce_nonce`. Discord's duplicate protection is time-limited, not permanent
idempotency: inspect history after uncertain delivery before retrying. A positive
receipt includes Discord's message ID, permalink, timestamps and content hash.
Edit first fetches the message and checks bot authorship and the caller's expected
content hash. This reduces stale edits but is not an atomic conditional update;
concurrent Discord edits can race. A timeout or transport failure does not prove
a write failed. Neither the connector nor caller should blindly retry writes.
Every request has a 30-second timeout; bounded multi-page scans can take longer.

## Local versus cloud

Local stdio runs on the host where Codex launches PHP. A local config entry does
not automatically make this server reachable from a cloud chat or another host.
Cloud use requires installing the code/runtime and secrets in that execution
environment, explicitly supported remote stdio, or separately deployed authenticated
HTTP/tunnel infrastructure. This release implements stdio only; it opens no port,
deploys no remote service and has not been live-verified in cloud chats.

Sources verified during implementation:
[Codex MCP configuration](https://learn.chatgpt.com/docs/extend/mcp?surface=cli),
[official PHP MCP SDK](https://github.com/modelcontextprotocol/php-sdk),
[Discord message API](https://docs.discord.com/developers/resources/message),
[Discord rate limits](https://docs.discord.com/developers/topics/rate-limits).

## Verification

`composer unit` uses injected transports, synthetic messages and temporary streams.
`php scripts/smoke.php` exercises SDK initialization, tool listing and a tool call
over stdio with an offline fixture process. `composer cs-check` checks formatting.
CI covers PHP 8.3-8.5 on Linux and Windows. Offline success proves protocol and
adapter behavior; it does not prove Discord permissions, real bot access, host
integration, delivery, or cloud reachability. No live messages are sent by tests.
