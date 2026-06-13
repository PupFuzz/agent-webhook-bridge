#!/usr/bin/env bash
# start-channel-session.sh — canonical launcher for agent-webhook-bridge live-wake.
#
# Self-resolves the channel identity, auto-detects UDS vs HTTP transport, applies the
# matching deaf-session guards (FR #2444 / DL-154/155), EXPORTS the resolved identity so
# the channel server binds exactly what was guarded, then execs `claude`.
#
# One launcher, no per-agent hardcoding, across the heterogeneous fleet:
#   (A) multiple agents, SAME host      -> UDS (per-agent unix socket)
#   (B) agents on SEPARATE Linux hosts  -> HTTP/TCP over an SSH reverse tunnel
#   (C) Windows host                    -> use the start-claude.ps1 / .bat companion
#                                          (no pgrep / curl --unix-socket / sed there)
#
# Channels are a research-preview feature: `--dangerously-load-development-channels` MUST
# be passed on every session (there is no settings.json / .mcp.json way to auto-load it).
# The MCP server keyed by the channel name must be defined in a `.mcp.json` Claude Code
# loads from the CWD (this script cd's to $HOME — change that if yours lives elsewhere).
#
# Usage:  start-channel-session.sh [--channel <name>] [extra claude args…]
set -euo pipefail

# ── 1. Resolve the channel identity ──────────────────────────────────────────────────
# Order: --channel > $BRIDGE_CHANNEL_NAME > settings.local.json .env.BRIDGE_CHANNEL_NAME
#        > "<namespace>-<agent>" from $COORD_CONFIG's .bridge.channel_namespace + $COORD_AGENT.
#
# settings.local.json is LOAD-BEARING: this launcher runs in the LOGIN shell, which does
# NOT inherit the env Claude Code injects into a *session* (settings.local.json `.env`),
# so a launcher that trusts exported env alone silently fails to resolve — the #1
# "can't resolve channel" cause. Read the key from the file (jq) as the fallback.
SETTINGS="${CLAUDE_SETTINGS_LOCAL:-$HOME/.claude/settings.local.json}"
_senv() {  # echo settings.local.json .env.<key>; empty if absent / no jq
    if [ -r "$SETTINGS" ] && command -v jq >/dev/null 2>&1; then
        jq -r --arg k "$1" '.env[$k] // empty' "$SETTINGS" 2>/dev/null || true
    fi
}

CHANNEL=""
if [ "${1:-}" = "--channel" ]; then
    [ -n "${2:-}" ] || { echo "--channel needs a value" >&2; exit 1; }
    CHANNEL="$2"; shift 2
fi
CHANNEL="${CHANNEL:-${BRIDGE_CHANNEL_NAME:-$(_senv BRIDGE_CHANNEL_NAME)}}"
if [ -z "$CHANNEL" ]; then
    CFG="${COORD_CONFIG:-$(_senv COORD_CONFIG)}"
    AGENT="${COORD_AGENT:-$(_senv COORD_AGENT)}"
    if [ -n "$CFG" ] && [ -n "$AGENT" ] && command -v jq >/dev/null 2>&1; then
        NS="$(jq -r '.bridge.channel_namespace // empty' "$CFG" 2>/dev/null || true)"
        [ -n "$NS" ] && CHANNEL="${NS}-${AGENT}"
    fi
fi
[ -n "$CHANNEL" ] || {
    echo "cannot resolve channel name. Pass --channel, export BRIDGE_CHANNEL_NAME," >&2
    echo "or set COORD_CONFIG + COORD_AGENT (shell env, or settings.local.json .env)." >&2
    exit 1
}

# ── 2. Transport (UDS default; HTTP for the reverse-tunnel topology) ──────────────────
# Normalize to the channel server's vocabulary ('unix' | 'http'); accept 'uds' as an
# alias for 'unix'. Anything that isn't 'http' is treated as UDS.
TRANSPORT="$(printf '%s' "${BRIDGE_CHANNEL_TRANSPORT:-$(_senv BRIDGE_CHANNEL_TRANSPORT)}" | tr '[:upper:]' '[:lower:]')"
case "$TRANSPORT" in
    ''|uds|unix) TRANSPORT="unix" ;;   # 'uds' is an alias for the server's 'unix'
    http)        TRANSPORT="http" ;;
    *) echo "BRIDGE_CHANNEL_TRANSPORT must be 'unix' (or 'uds') or 'http' — got '${TRANSPORT}'." >&2; exit 1 ;;
esac
PORT="${BRIDGE_CHANNEL_PORT:-$(_senv BRIDGE_CHANNEL_PORT)}"
SERVER_DIR="${BRIDGE_CHANNEL_SERVER_DIR:-$HOME/agent-webhook-bridge-channel}"
RUNTIME="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
# Honor an explicit BRIDGE_CHANNEL_SOCKET (env or settings) so the guard matches the
# server's own SOCKET_PATH; else the per-name default the server also derives.
SOCK="${BRIDGE_CHANNEL_SOCKET:-$(_senv BRIDGE_CHANNEL_SOCKET)}"
SOCK="${SOCK:-$RUNTIME/agent-webhook-bridge-channel-${CHANNEL}.sock}"

# Marker MUST mirror the server's markerPath(): UDS = <sock>.FAILED;
# HTTP = $RUNTIME/agent-webhook-bridge-channel-<channel>.http-<port>.FAILED.
if [ "$TRANSPORT" = "http" ]; then
    [ -n "$PORT" ] || { echo "HTTP transport needs BRIDGE_CHANNEL_PORT (env or settings.local.json .env)." >&2; exit 1; }
    MARKER="$RUNTIME/agent-webhook-bridge-channel-${CHANNEL}.http-${PORT}.FAILED"
else
    MARKER="${SOCK}.FAILED"
fi

# ── 3. Single-session guard (FR #2444; transport-agnostic — matches the argv) ─────────
# Covers a session started OUTSIDE this wrapper and the HTTP topology with no socket to
# collide on. A guardrail (a cmdline match), not a guarantee: the connector's own
# EADDRINUSE refusal + the visible marker (step 4) is the backstop.
if pgrep -f -- "--dangerously-load-development-channels[[:space:]]+server:${CHANNEL}([[:space:]]|\$)" >/dev/null 2>&1; then
    echo "A Claude Code session is already running channel '${CHANNEL}'. Refusing to start a second — it would come up deaf to live-wake. Close the other session first." >&2
    exit 1
fi

# ── 4. Surface (NEVER clear) a prior deaf-session marker (DL-154/155) ─────────────────
# The channel server owns the marker lifecycle and clears it on the next successful bind
# (which this launch triggers); a silent rm here would destroy the signal before you see it.
if [ -f "$MARKER" ]; then
    echo "WARNING: a previous session for channel '${CHANNEL}' came up DEAF to live-wake:" >&2
    sed 's/^/  /' "$MARKER" >&2 || true   # best-effort; never blocks launch
    echo "  (the imminent bind clears this marker if it succeeds.)" >&2
fi

# ── 5. Stale-listener guard (transport-specific) ──────────────────────────────────────
if [ "$TRANSPORT" = "http" ]; then
    if curl -s -o /dev/null --max-time 1 "http://127.0.0.1:${PORT}/" 2>/dev/null; then
        echo "A channel server is already listening on 127.0.0.1:${PORT} — a session is up. Aborting." >&2
        exit 1
    fi
elif [ -S "$SOCK" ]; then
    if curl -s -o /dev/null --max-time 1 --unix-socket "$SOCK" http://localhost/ 2>/dev/null; then
        echo "A channel server is already listening at $SOCK — a session is up. Aborting." >&2
        exit 1
    fi
    echo "Removing stale channel socket (no listener): $SOCK"
    rm -f "$SOCK"
fi

# ── 6. One-time channel-server deps (pinned lock; the server reads a bearer token) ────
if [ -d "$SERVER_DIR" ] && [ ! -d "$SERVER_DIR/node_modules" ]; then
    echo "Installing channel-server deps (one-time, pinned via npm ci)…"
    ( cd "$SERVER_DIR" && { npm ci || npm install; } )
fi

# ── 7. Export the resolved identity so the server binds what we just guarded ──────────
# The channel server (.mjs) derives its socket/port/marker from these env vars; exporting
# the resolved values here guarantees it binds the SAME endpoint this launcher guarded,
# rather than re-deriving from a possibly-divergent ~/.mcp.json env — which would silently
# guard one path while the server binds another (the deaf-session class we're closing).
export BRIDGE_CHANNEL_NAME="$CHANNEL"
export BRIDGE_CHANNEL_TRANSPORT="$TRANSPORT"
if [ "$TRANSPORT" = "http" ]; then
    export BRIDGE_CHANNEL_PORT="$PORT"
elif [ -z "${BRIDGE_CHANNEL_SOCKET:-}" ]; then
    export BRIDGE_CHANNEL_SOCKET="$SOCK"   # else the operator's explicit value is already in env
fi

# ── 8. Launch ─────────────────────────────────────────────────────────────────────────
cd "$HOME"   # so a home-rooted ~/.mcp.json is loaded
exec claude --dangerously-load-development-channels "server:${CHANNEL}" "$@"
