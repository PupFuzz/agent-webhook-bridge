#!/usr/bin/env bash
# start-claude.sh — canonical launcher for agent-webhook-bridge live-wake (the Linux half of
# the cross-platform launcher; start-claude.ps1 + .bat are the Windows half).
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
# WHERE IT RUNS FROM (card#11328): the client pack ships this file, and a bootstrapped seat
# runs it through `<client root>/bin/start-claude` — a shim the client updater rewrites at every
# pack install, which runs the copy of the release `current.json` names. The seat's own
# `~/start-claude.sh` is a stable shim the provisioner writes (`--write-launcher-shim`) that sets
# the channel identity and execs that. Never copy this file to a seat: a copy does not update.
# `examples/channel-servers/README.md` § The seat's launcher owns that contract.
#
# Channels are a research-preview feature: `--dangerously-load-development-channels` MUST
# be passed on every session (there is no settings.json / .mcp.json way to auto-load it).
# The MCP server keyed by the channel name must be defined in a `.mcp.json` Claude Code
# loads from the CWD (this script cd's to $HOME).
#
# Usage:  start-claude.sh [--channel <name>] [extra claude args…]
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
RUNTIME="${XDG_RUNTIME_DIR:-/run/user/$(id -u)}"
# Honor an explicit BRIDGE_CHANNEL_SOCKET (env or settings) so the guard matches the
# server's own SOCKET_PATH; else the per-name default the server also derives.
SOCK="${BRIDGE_CHANNEL_SOCKET:-$(_senv BRIDGE_CHANNEL_SOCKET)}"
SOCK="${SOCK:-$RUNTIME/agent-webhook-bridge-channel-${CHANNEL}.sock}"

# Marker MUST mirror the server's markerPath(): UDS = <sock>.FAILED;
# HTTP = $RUNTIME/agent-webhook-bridge-channel-<channel>.http-<port>.FAILED.
if [ "$TRANSPORT" = "http" ]; then
    [ -n "$PORT" ] || { echo "HTTP transport needs BRIDGE_CHANNEL_PORT (env or settings.local.json .env)." >&2; exit 1; }
    # Mirror the server's HTTP marker base exactly: XDG_RUNTIME_DIR || os.tmpdir()
    # (= $TMPDIR or /tmp on Linux). NOT $RUNTIME's /run/user/<uid> fallback — when
    # XDG is unset (ssh 'cmd' / headless, common on the tunnel topology) that would
    # look in the wrong dir and never find the server's marker.
    HTTP_BASE="${XDG_RUNTIME_DIR:-${TMPDIR:-/tmp}}"
    MARKER="${HTTP_BASE%/}/agent-webhook-bridge-channel-${CHANNEL}.http-${PORT}.FAILED"
else
    MARKER="${SOCK}.FAILED"
fi

# ── 3. Single-session guard (FR #2444; transport-agnostic — matches the argv) ─────────
# Covers a session started OUTSIDE this wrapper and the HTTP topology with no socket to
# collide on. A guardrail (a cmdline match), not a guarantee: the connector's own
# EADDRINUSE refusal + the visible marker (step 4) is the backstop.
if pgrep -f -- "--dangerously-load-development-channels[[:space:]]+server:${CHANNEL}([[:space:]]|\$)" >/dev/null 2>&1; then
    echo "A Claude Code session is already running channel '${CHANNEL}'. Refusing to start a second — it would come up deaf to live-wake. Close the other session first." >&2
    # Re-provisioning (`provision-board-tools.py --role b`) is the case that lands here
    # most often: the running session's channel server still holds the address, so this
    # launch would lose the bind. /mcp reconnect does not stop the previous channel
    # server — restart the session. Details: docs/board-tools-enablement.md § Activating
    # on a running seat.
    echo "  If you just re-provisioned: /mcp reconnect does not stop the previous channel server — restart the session (close that session, then re-run this). Details: docs/board-tools-enablement.md § Activating on a running seat" >&2
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
# A held local port may be a LIVE session OR a crashed-Claude orphan still holding it. For HTTP we
# probe to tell them apart (405-is-alive: ANY HTTP response proves the bridge is handling — only a
# connection-level failure means dead/wedged) and reclaim ONLY a verified-dead orphan that is
# demonstrably our channel server (port-holder ∩ awb cmdline, never an arbitrary listener). UDS
# self-heals below (a socket with no listener is removed). This launcher `exec`s claude, so there
# is no post-exit trap to mirror the PowerShell `finally` reap — the next launch's startup reclaim
# is the self-heal for a hard-killed orphan instead.
_port_listeners() {   # PIDs holding $1/tcp in LISTEN (ss preferred, lsof fallback), one per line
    if command -v ss >/dev/null 2>&1; then
        ss -H -ltnp "sport = :$1" 2>/dev/null | grep -oE 'pid=[0-9]+' | cut -d= -f2 | sort -u
    elif command -v lsof >/dev/null 2>&1; then
        lsof -tiTCP:"$1" -sTCP:LISTEN 2>/dev/null | sort -u
    fi
}
_bridge_responding() {   # 0 if anything answers HTTP on $1 (incl. 405); 1 only on connection failure
    # FAIL SAFE: if we can't probe (curl absent), assume ALIVE -> refuse, never blind-reclaim. Without
    # this, a missing curl makes every probe read "dead" and the reclaim would kill a LIVE session's
    # own channel server (it is itself a verified awb server), bypassing the single-session guard.
    command -v curl >/dev/null 2>&1 || return 0
    local code
    for _ in 1 2 3; do
        # `|| code=000`, never `|| echo 000` inside the substitution: curl itself writes `000` for
        # %{http_code} when it gets no answer and THEN exits non-zero, so an appended `000` reads
        # `000000` — "alive" — and the reclaim below could never run.
        code="$(curl -s -o /dev/null -m 3 -w '%{http_code}' "http://127.0.0.1:$1/" 2>/dev/null)" || code=000
        [ "$code" != "000" ] && return 0    # any HTTP status (incl. 405 Method Not Allowed) == alive
        sleep 0.5
    done
    return 1
}
_reclaim_stale_channel_server() {   # kill ONLY a verified awb channel server holding $1; wait for release
    local port="$1" killed="" pid _pid_args
    for pid in $(_port_listeners "$port"); do
        # Intersection (not union): the holder PID must ALSO match the awb channel-server cmdline.
        # Captured, then a pipe-free glob match — never `ps … | grep -q` (card#8080): `grep -q` exits on
        # the first match and SIGPIPEs `ps`, so under `pipefail` a cmdline that DOES match reads as
        # "not an awb channel server" — the safe direction, which is why it would go unnoticed.
        _pid_args="$(ps -p "$pid" -o args= 2>/dev/null || true)"
        if [[ $_pid_args == *agent-webhook-bridge-channel* || $_pid_args == *agent-webhook-bridge/client/*/entry.mjs* ]]; then
            echo "Reclaiming port $port: killing orphan channel server PID $pid." >&2
            kill -TERM "$pid" 2>/dev/null; sleep 0.5; kill -KILL "$pid" 2>/dev/null || true
            killed=1
        else
            echo "Port $port held by PID $pid ($(ps -p "$pid" -o comm= 2>/dev/null)) — NOT an agent-webhook-bridge channel server; refusing to kill it." >&2
        fi
    done
    [ -n "$killed" ] || return 1
    for _ in $(seq 1 20); do            # wait up to ~5s for TCP teardown
        sleep 0.25
        [ -z "$(_port_listeners "$port")" ] && return 0
    done
    return 1                            # didn't release -> caller aborts
}
_refuse_held_port() {
    echo "The channel port is already held by a process on 127.0.0.1:${PORT} (a running session, or a channel server left behind by one) — refusing to start a second." >&2
}

if [ "$TRANSPORT" = "http" ]; then
    if command -v ss >/dev/null 2>&1 || command -v lsof >/dev/null 2>&1; then
        if [ -n "$(_port_listeners "$PORT")" ]; then
            if _bridge_responding "$PORT"; then
                _refuse_held_port
                for pid in $(_port_listeners "$PORT"); do echo "  port ${PORT} held by PID ${pid}: $(ps -p "$pid" -o args= 2>/dev/null)" >&2; done
                exit 1
            fi
            echo "Port ${PORT} is held but not responding as a healthy bridge — stale orphan from a prior session. Reclaiming." >&2
            if ! _reclaim_stale_channel_server "$PORT"; then
                echo "Could not reclaim port ${PORT} (holder is not a recognizable channel server, or it did not release). Clear it manually, then relaunch." >&2
                exit 1
            fi
            echo "Reclaimed port ${PORT}; continuing startup." >&2
        fi
    else
        # No port-inspection tool: the orphan reclaim cannot run, so fall back to the plain probe
        # (refuse on any answer) and say why a crashed-session orphan will not be self-healed.
        echo "WARNING: neither 'ss' nor 'lsof' found — cannot detect a stale channel-server orphan on 127.0.0.1:${PORT}; a crashed-session orphan would resurface as EADDRINUSE-deaf. Install iproute2 (ss) or lsof to enable self-heal." >&2
        if command -v curl >/dev/null 2>&1 && curl -s -o /dev/null --max-time 1 "http://127.0.0.1:${PORT}/" 2>/dev/null; then
            _refuse_held_port
            exit 1
        fi
    fi
elif [ -S "$SOCK" ]; then
    if ! command -v curl >/dev/null 2>&1; then
        # Fail SAFE like the HTTP-port path: with no curl we cannot tell a live listener
        # from a stale socket — never rm a possibly-live socket on a blind probe.
        echo "curl not found — cannot probe $SOCK for a live listener. If no session is up, remove it manually: rm -f $SOCK" >&2
        exit 1
    fi
    if curl -s -o /dev/null --max-time 1 --unix-socket "$SOCK" http://localhost/ 2>/dev/null; then
        echo "The channel socket is already held by a process at $SOCK (a running session, or a channel server left behind by one) — refusing to start a second." >&2
        exit 1
    fi
    echo "Removing stale channel socket (no listener): $SOCK"
    rm -f "$SOCK"
fi

# ── 6. One-time channel-server deps — ONLY for a channel server run from a copy ─────────
# A client root ships its node_modules in the pack, so a launch through `<root>/bin/start-claude`
# (which sets AWB_LAUNCHER_CLIENT_ROOT) has nothing to install and does not read
# BRIDGE_CHANNEL_SERVER_DIR at all. That variable is kept only for a seat still running a copied
# channel server (pinned lock; `npm ci`), which is off the update path until it is bootstrapped.
if [ -z "${AWB_LAUNCHER_CLIENT_ROOT:-}" ]; then
    SERVER_DIR="${BRIDGE_CHANNEL_SERVER_DIR:-$HOME/agent-webhook-bridge-channel}"
    if [ -d "$SERVER_DIR" ] && [ ! -d "$SERVER_DIR/node_modules" ]; then
        echo "Installing channel-server deps (one-time, pinned via npm ci)…"
        ( cd "$SERVER_DIR" && { npm ci || npm install; } )
    fi
fi
unset AWB_LAUNCHER_CLIENT_ROOT

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
