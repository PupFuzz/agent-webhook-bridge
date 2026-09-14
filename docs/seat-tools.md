# Seat tools: getting bridge programs onto an agent's seat

A **seat tool** is a bridge program an agent runs **on its own seat**, as its own OS user, where there is commonly **no bridge checkout**. `bridge:check` names such a tool by the name it has on the seat's `PATH` (DL-385), so the seat has to be able to get it there. This doc owns how: staging a pack, installing it, keeping it current, and what each end can and cannot see.

## Which programs are seat tools

`seat-tools.json` at the repo root is the **one declaration**. Nothing under `bin/` is a seat tool unless that file lists it; everything else there is contributor or CI tooling, or checkout-bound by design (`bin/provision-board-tools.py` reads its own checkout's `examples/`). Print the current set rather than trusting a copy of it:

```bash
jq -r '.tools[]' seat-tools.json
```

A declared tool must **run copied alone**: stdlib-only, no read of anything beside its own file, and tracked at mode `100755`. `bin/test_seat_tools.py` checks this by running each one (`--help`) as a lone copy under `env -i` from an empty directory. That check reaches only what `--help` executes, so a sibling-file read on a path only a real run takes stays yours to avoid. To add a seat tool, add its `bin/` path to `seat-tools.json`; the tests and `bin/seat-pack.py` pick it up from there.

## Staging a pack — `bin/seat-pack.py`

Run it from a bridge checkout **pinned to the release the fleet should run** (`git checkout v<version>`), not a moving branch. A host with both a `-dev` and a `-prod` checkout stages from the one the seats should run.

```bash
python3 bin/seat-pack.py --out <dir>                 # copy shape — what a PM stages for its impl seats
python3 bin/seat-pack.py --out <dir> --shape link    # link shape — for a seat that has this checkout
```

| Shape | Writes under `<dir>` |
| --- | --- |
| `copy` (default) | `seat-tools/bin/<tool>` (mode `0755`), `seat-tools/VERSION`, `seat-tools/seat-pack.json` (`schema`, `bridge_version`, `bridge_describe`, and each tool's `name` and `sha256`), and `channel-setup/` — every tracked file under `examples/channel-servers/` outside `node_modules/`, so `channel-lib.mjs` travels with the entry |
| `link` | `seat-tools/bin/<tool>` only, each a symlink into this checkout. **No metadata**: the checkout is the version, and `git pull` would leave a metadata file beside it stale |

- **The output is deterministic.** It has no timestamps, so two runs over one tree are byte-identical. To answer "is the staged pack current?", regenerate it into a temp dir and `diff -r` the two.
- **A re-run replaces `seat-tools/` whole.** A tool dropped from the declaration does not survive, and neither does metadata left by an earlier copy-shape run.
- **`channel-setup/` is written OVER, the way `cp -a examples/channel-servers/.` writes over a snapshot.** Nothing in it is deleted, so a deployment running straight out of the staged directory keeps its `node_modules/`. The cost is that a file the reference stops shipping stays behind. The seat still runs `npm ci` there, as before.
- `bridge_describe` is `git describe --tags --always --dirty` of the staging checkout. A pack staged from an untagged commit or an edited tree says so.
- Exit `0` means written. Exit `1` means refused, and the reason is on stderr: an entry is not a tracked `100755` file directly under `bin/`, two entries share an install name, or git failed. Exit `2` is a usage error.

⚠ **Commit a staged pack with its exec bits.** Git records the mode, and a seat links to the file git checks out: a tool committed at `100644` resolves on `PATH` and exits `126`. Stage with `git add --chmod=+x <dir>/seat-tools/bin/*` and verify before pushing:

```bash
git ls-files -s <dir>/seat-tools/bin    # every line must start 100755
```

## Installing onto PATH — link shape only

Install with the coord plugin's `hooks/bin/install-linked-bin.sh` **in its default link arm**:

| Seat | Command |
| --- | --- |
| Has a bridge checkout (solo, a PM's own seat) | `python3 bin/seat-pack.py --shape link --out ~/.local/share/agent-webhook-bridge/seat-pack`, then `bash <coord-plugin>/hooks/bin/install-linked-bin.sh ~/.local/share/agent-webhook-bridge/seat-pack/seat-tools/bin ~/.local/bin` |
| No checkout (an impl seat) | `bash <coord-plugin>/hooks/bin/install-linked-bin.sh <coordination-clone>/OUTBOUND/<agent>/seat-tools/bin ~/.local/bin`, where the PM has staged the copy shape into `OUTBOUND/<agent>/` |

Verify by **running** the tool, not by finding it: `check-channel-snapshot.py --help` must exit 0. `command -v` also succeeds on a link that resolves to a file that cannot execute.

**Unsupported, named so nobody reaches for them:**
- **`--shape=copy` into a shared target such as `~/.local/bin`.** The installer keys its copy manifest by the TARGET directory alone, so a second source copied into a target the toolkit was already copied into overwrites the record of the first. That stays unsupported until the framework keys its manifests by target and source.
- **A seat whose target directory probes `NOT_CAPABLE`**, i.e. it cannot hold a symlink. There is no supported PATH install for it today. It can still run the staged file by its full path (`python3 <coordination-clone>/OUTBOUND/<agent>/seat-tools/bin/check-channel-snapshot.py <deployed dir>`).

**Trust boundary, in one sentence:** linking an impl seat's `PATH` into the coordination clone means whoever can push to `OUTBOUND/<agent>/` changes code that seat runs next, which is the same boundary this install already accepts for the PM-staged `channel-setup/` that runs as a long-lived MCP server on every impl seat — link and copy differ only in whether a human-run install step sits between the push and the run.

## Upgrades, and what each end can see

- **The PM stages and re-stages.** After each bridge upgrade, it regenerates the copy shape into every `OUTBOUND/<agent>/` and commits the pack with its exec bits. `seat-tools/seat-pack.json` makes the staged version readable against the PM's own bridge: compare `bridge_version` with the checkout's `VERSION`, or regenerate to a temp dir and `diff -r`. `CLAUDE_DEPLOYMENT.md` § Reconcile out-of-repo copies carries this as an update step.
- **An impl seat picks upgrades up with `git pull`** of the coordination clone. Its `PATH` entry is a link into the staged file, so no re-install is needed unless a tool is added. Whether its installed tools match what is staged can be checked on the impl seat itself: resolve the link, hash the file, and compare with `seat-pack.json`.
- ⛔ **The PM cannot see what an impl seat actually installed, and nothing here gives it that view.** Nothing the PM runs reads the impl seat's home, and no call reports it. Building that view would extend what the board-tools door accepts, and nothing reported today needs it (DL-385).

## Bridge releases older than the floor

`v0.86.0` is the first release carrying `bin/seat-pack.py`. A bridge pinned below it has no pack to stage and no seat tools declared. Stage `channel-setup/` the way the release you are on documents it (`cp -a examples/channel-servers/. <dir>/channel-setup/`), and read that release's docs for how its `check-channel-snapshot.py` is run. `tests/Unit/Docs/SeatPackFloorTest.php` holds that version against the `docs/CHANGELOG.md` section that first carries `bin/seat-pack.py`.
