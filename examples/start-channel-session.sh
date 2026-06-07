#!/usr/bin/env bash
# Start a Claude Code session with an agent-webhook-bridge channel loaded, so the
# bridge's channel_push delivers live to this session.
#
# Channels are a research-preview feature: `--dangerously-load-development-channels`
# MUST be passed on every session — there is NO settings.json / .mcp.json way to
# auto-load it (it deliberately bypasses the channel allowlist). This wrapper is
# that command, plus a stale-socket guard and a one-time deps install.
#
# Usage:  start-channel-session.sh [CHANNEL_NAME] [-- extra claude args...]
#   CHANNEL_NAME defaults to $BRIDGE_CHANNEL_NAME, else "kanbanboard-agent".
#   The MCP server keyed by CHANNEL_NAME must be defined in a .mcp.json that
#   Claude Code loads from the CWD (this script cd's to $HOME — change that if
#   your .mcp.json lives elsewhere). CHANNEL_NAME must equal the agent YAML's
#   channel.socket basename and the channel server's BRIDGE_CHANNEL_NAME.
set -euo pipefail

CHANNEL_KEY="${1:-${BRIDGE_CHANNEL_NAME:-kanbanboard-agent}}"
SOCK="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}/agent-webhook-bridge-channel-${CHANNEL_KEY}.sock"
SERVER_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/channel-servers"

# The channel server refuses to bind if the socket file already exists. Remove a
# stale one (no listener); abort if a live session already holds it.
if [ -S "$SOCK" ]; then
    if curl -s -o /dev/null --max-time 1 --unix-socket "$SOCK" http://localhost/ 2>/dev/null; then
        echo "A channel server is already listening at $SOCK — a session is up. Aborting." >&2
        exit 1
    fi
    echo "Removing stale channel socket (no listener): $SOCK"
    rm -f "$SOCK"
fi

# One-time channel-server deps (node_modules is gitignored — absent after clone).
# `npm ci` installs the exact pinned tree from the committed package-lock.json.
if [ -d "$SERVER_DIR" ] && [ ! -d "$SERVER_DIR/node_modules" ]; then
    echo "Installing channel-server deps (one-time, pinned via npm ci)…"
    (cd "$SERVER_DIR" && npm ci)
fi

cd "$HOME"   # so a home-rooted ~/.mcp.json is loaded
exec claude --dangerously-load-development-channels "server:${CHANNEL_KEY}" "${@:2}"
