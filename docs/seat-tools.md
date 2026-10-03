# Seat tools: getting bridge programs onto an agent's seat

A **seat tool** is a bridge program an agent runs **on its own seat**, as its own OS user, where there is commonly **no bridge checkout**. `bridge:check` names such a tool by the name it has on the seat's `PATH` (DL-385), so the seat has to be able to get it there. This doc owns how: what ships a seat tool, installing it onto `PATH`, keeping it current, and what each end can and cannot see.

## Which programs are seat tools

`seat-tools.json` at the repo root is the **one declaration**. Nothing under `bin/` is a seat tool unless that file lists it; everything else there is contributor or CI tooling, or checkout-bound by design (`bin/provision-board-tools.py` reads its own checkout's `examples/`). Print the current set rather than trusting a copy of it:

```bash
jq -r '.tools[]' seat-tools.json
```

A declared tool must **run copied alone**: stdlib-only, no read of anything beside its own file, and tracked at mode `100755`. `bin/test_seat_tools.py` checks this by running each one (`--help`) as a lone copy under `env -i` from an empty directory. That check reaches only what `--help` executes, so a sibling-file read on a path only a real run takes stays yours to avoid. To add a seat tool, add its `bin/` path to `seat-tools.json`; the tests and `bin/build-client-pack.py` pick it up from there.

## How a seat gets them — the client pack

Seat tools ship **inside the channel-server client pack** the bridge publishes for each release (`bin/build-client-pack.py`, DL-428; published by `bridge:client-pack:install`, DL-430). A seat on the self-updating client (bootstrapped onto its client root — `provision-board-tools.py --role b --bootstrap-client`, or any onboarding entry point, DL-444/DL-445) gets them with the pack:

- each release's copy is at `<root>/versions/<release>/seat-tools/bin/<tool>`;
- `<root>/bin/<tool>` is a **shim** (`<tool>.cmd` on Windows) that runs the copy from the release `<root>/current.json` names, resolved each time it runs.
- The same root also gets a shim for each **client program** the release carries under `client/bin/` — `bridge-board-call` (card#11151, DL-451) — named without its `.mjs` and run with `node`, because it imports the client beside it. It is not a seat tool: it is not in `seat-tools.json` and does not run copied alone. `examples/channel-servers/README.md` § *Calling a board tool from a script* owns it.

`<root>` is `${XDG_DATA_HOME:-~/.local/share}/agent-webhook-bridge/client/<channel>` (`%LOCALAPPDATA%\agent-webhook-bridge\client\<channel>` on Windows). `examples/channel-servers/README.md` § *Installed and updated by the bridge* owns the root's layout.

**Trust:** whoever can publish a pack on the seat's bridge — and, for an agent with `approval_required`, approve it — changes the code on that seat's `PATH` at its next launch. DL-428 and DL-430 own that boundary.

There is **no manual staging step** and nothing for a PM to re-stage after an upgrade. The earlier path — a PM staging a pack into the coordination repo for each seat to link into — is retired (DL-447). Clearing what it left in coordination repos is the coord framework's upgrade to do (`coord:update`), not this repo's.

## Installing onto PATH

Link the root's shims into a directory on `PATH`, with the coord plugin's `hooks/bin/install-linked-bin.sh` **in its default link arm**:

```bash
bash <coord-plugin>/hooks/bin/install-linked-bin.sh <root>/bin ~/.local/bin
```

Verify by **running** the tool, not by finding it: `check-channel-snapshot.py --help` must exit 0. `command -v` also succeeds on a link that resolves to a file that cannot execute.

The remedy `bridge:check` prints names the tool by its BASENAME, so this install is correct only while the installer links each file under its own basename with no rename (its link arm names each link `$(basename -- "$f")` in the target). That condition is the installer's to keep. On this side, `Tests\Support\AssertsSeatToolRemedy` holds the remedy to a declared tool's basename, and the `--help` run above is what checks the link on the seat.

**Other seats:**
- **A seat with a bridge checkout** can run a tool from it directly: `python3 bin/check-channel-snapshot.py` is the same program.
- **A seat still on a copied channel server** (not bootstrapped) has no client root and so no shims. Bootstrap it; the tools arrive with the first pack. The bootstrap runs **once, from a bridge checkout on that seat** (it runs that checkout's updater), so a seat with no checkout needs one for that step — [`CLAUDE_DEPLOYMENT.md`](../CLAUDE_DEPLOYMENT.md) § *Multi-agent channel-server distribution* owns it. While its bridge publishes no pack, the bootstrap installs nothing, and the tool is reachable only from that checkout. A seat that is not bootstrapped and has no checkout has no route to the tool.

**Unsupported, named so nobody reaches for them:**
- **`--shape=copy` into a shared target such as `~/.local/bin`.** The installer keys its copy manifest by the TARGET directory alone, so a second source copied into a target the toolkit was already copied into overwrites the record of the first. That stays unsupported until the framework keys its manifests by target and source.
- **A seat whose target directory probes `NOT_CAPABLE`**, i.e. it cannot hold a symlink. There is no supported PATH install for it today. It can still run the shim by its full path (`<root>/bin/check-channel-snapshot.py <deployed dir>`, or `<root>\bin\check-channel-snapshot.py.cmd <deployed dir>` on Windows).

## Upgrades, and what each end can see

- **Nothing to do per upgrade.** The seat's client updates itself at its next launch from what its bridge publishes (DL-434), and a shim resolves `current.json` each time it runs, so a CHANGED tool needs no re-install. The launch rewrites the shims from the installed release's `FILES.json`: a tool the declaration GAINS gets a shim in `<root>/bin/`, but it reaches `PATH` only when `install-linked-bin.sh` is re-run; a tool it DROPS has its shim removed, which leaves a dangling link on `PATH` that the install does not remove, so remove it by hand.
- **The PM sees each seat's client from the bridge**: `bridge:client-fleet` (or the `client_fleet` op for a seat with `board_tools.fleet_view`) shows the release each seat reports running and installed (DL-432). What is on a seat's `PATH` is not reported; nothing the PM runs reads the seat's home.
