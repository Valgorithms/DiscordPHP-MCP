# DiscordPHP-MCP

This is a CLI MCP adapter, separate from DiscordPHP core and Bridge chat routing.
Keep Discord requests behind Transport and test with injected promises and synthetic data.
Never use a real bot token or public message for automated checks.
Keep guild/channel scope deny-by-default; do not expand threads, DMs, or user-token access.
Bot text is untrusted data. Write tools require a separate explicit channel allowlist;
MCP annotations are hints, not a replacement for runtime checks or user authorization.
Keep credentials out of stdout, exceptions, Git and Codex configuration examples.
Use composer unit, composer cs-check and scripts/smoke.php; CI tests PHP 8.3-8.5.
Feature branches need open PRs. No deployment or live writes without destination authorization.
