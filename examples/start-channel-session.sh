#!/usr/bin/env bash
# start-channel-session.sh — COMPATIBILITY STUB (card#11328, DL-463). The launcher moved to
# channel-servers/bin/start-claude.sh; this file only runs it, so a seat whose ~/start-claude.sh
# still execs this path from a checkout keeps launching after a pull. Run from here, the launcher
# reads BRIDGE_CHANNEL_SERVER_DIR as it always did. Move such a seat onto its self-updating shim
# (docs/CHANGELOG.md, the card#11328 Upgrade warning), then stop using this path.
set -euo pipefail
here="$(cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")" && pwd)"
exec bash "$here/channel-servers/bin/start-claude.sh" "$@"
