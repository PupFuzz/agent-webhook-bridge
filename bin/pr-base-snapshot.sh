#!/usr/bin/env bash
#
# Prints THE BASE SNAPSHOT a `pull_request` workflow step must pair its work
# tree with — the FIRST PARENT of the merge commit `actions/checkout` leaves at
# HEAD — by running agent-board-toolkit's `bin/pr-base-snapshot`, which owns the
# derivation, its refusals and their exit codes (card#8527; hoisted to the
# toolkit by card#12338). Its header says why the base is neither
# `github.event.pull_request.base.sha` nor a `git merge-base`; read it there.
#
# This file only adapts the call shape the workflow steps use,
# `BASE=$(bin/pr-base-snapshot.sh "$HEAD_SHA")`, to the toolkit's `--head` flag.
# The toolkit is the checkout named by AGENT_BOARD_TOOLKIT_DIR, which
# .github/actions/agent-board-toolkit exports at its pinned SHA.
#
# EXIT CODES: 2 when AGENT_BOARD_TOOLKIT_DIR is unset or holds no
# bin/pr-base-snapshot; otherwise the toolkit tool's own, and every non-zero one
# means "no sha was printed".
set -euo pipefail

tool="${AGENT_BOARD_TOOLKIT_DIR:-}/bin/pr-base-snapshot"
if [ -z "${AGENT_BOARD_TOOLKIT_DIR:-}" ] || [ ! -x "$tool" ]; then
    echo "::error::bin/pr-base-snapshot.sh runs agent-board-toolkit's bin/pr-base-snapshot, and AGENT_BOARD_TOOLKIT_DIR ('${AGENT_BOARD_TOOLKIT_DIR:-}') holds no executable one." >&2
    echo "Fix: run the .github/actions/agent-board-toolkit step before this one, or point AGENT_BOARD_TOOLKIT_DIR at a toolkit checkout (v0.51.0 or later)." >&2
    exit 2
fi

exec "$tool" --head "${1:-}"
