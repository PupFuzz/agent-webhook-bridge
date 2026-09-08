# Board-tools enablement — who does what, and where the steps come from

Turning the two-way board window on for an agent that uses the **SSH-forced-command
transport** is an exchange between **three actors on two boxes**. This page owns the
ROLES and the HANDOFF. It deliberately does not repeat the steps: the steps are
generated, per agent, by `php artisan bridge:provision-tools --agent=<name>` — see
[§ Where the steps live](#where-the-steps-live).

## Roles

| actor | runs |
| --- | --- |
| **PM agent** (host A, the bridge's own OS user) | `bridge:provision-tools --agent=X …`; edits `X.yml`; saves the key line the seat posts; runs `bridge:check`. **Does NOT run STEP 3.** |
| **impl agent** (host B — its own seat, its own OS user) | `provision-board-tools.py --role b` from its own clone; posts its PUBLIC key line; runs `--role b --certify-only`; starts its session |
| **operator** (a human) | runs STEP 3 — the pin — after deciding the posted key is that seat's. `sudo` only when the forced-command account is not the one `bridge:provision-tools` ran as |

**The security statement, stated plainly.** The pin's only boundary is **a person choosing
which key line gets pinned**. No mechanism in this design enforces that a human made the
choice: an account that can write its own `authorized_keys` can pin anything, and a PM
agent holding the `.pub` can compute its fingerprint. So STEP 3 is addressed to the
OPERATOR as a **process** control, the packet tells the PM agent to stop and hand it over,
and `--expect-fingerprint` is a **transcription guard** (right file, right seat), never a
checkpoint.

⚠ **That statement is scoped to the CROSS-HOST path** — the one where a key line travels
between two boxes as text a human or an agent re-types, and where the only evidence of
provenance is somebody's judgement. **Same-box enablement differs, and not by being more
trusting:** the one-shot wrapper (below) runs `--role b` *as the agent's own OS user* and
hands `--role a` the resulting `.pub` **by path**, so no key line is ever transcribed and
the provenance of the file is established by root having watched it be generated. There is
nothing for a person to adjudicate there, and the packet does not ask them to.

## Handoff

- **The key line travels through the coordination surface, not a shell.** The seat posts
  the printed PUBLIC key line to the PM (a card comment, the coordination repo — whatever
  the two already use). The PM writes it to a file with a **quoted heredoc or a file-write
  tool**; the packet's STEP 2 spells that out and deliberately offers no one-liner that
  puts the value between quotes, because a quote inside the value would end the quoting.
- **The `Fingerprint:` line stays on the seat.** `--role b` prints it next to the public
  key; the operator reads it *there* when they run STEP 3. It is what
  `--expect-fingerprint` is compared against.
- ⛔ **`grep <host> ~/.ssh/known_hosts` is the wrong instrument for "is the host pinned?"**
  `HashKnownHosts` is on by default on most seats, so the host field is a salted hash and
  the grep finds nothing on a seat that IS pinned — an absence that reads as *no pin* and
  sends someone to re-pin a host that was fine. **`ssh-keygen -F <host>`** is the
  instrument: it hashes the query the same way and prints the matching line.
- **`--expect-fingerprint` is a transcription guard.** It catches the wrong file and the
  wrong seat. It does not, and cannot, establish that a human chose the key — see the
  security statement above.
- **ECDSA P-256, not ed25519.** A FIPS sshd **rejects** ed25519, so `--role b` generates a
  P-256 key and `bridge:check` FAILs a pinned ed25519 key on a FIPS seat.
- ⚠ **On a FIPS-restricted seat, a stale host pin reads like a rebuilt host.** Where
  `HostKeyAlgorithms` excludes ed25519 the host offers no ED25519 host key at all, so the
  *"host key verification failed / host key has changed"* refusal looks like the ED25519
  line having **gone entirely** rather than a key that moved. That is a stale pin against a
  narrowed algorithm list, not a rebuilt host: `ssh-keygen -R <host>` and let the next
  `--role b` / `--certify-only` run re-pin it.
- **After STEP 4, the seat's session must pick the merged `.mcp.json` up.** Restarting the
  Claude session does it. ⚠ **The channel server's `args` in that seat's `.mcp.json` were
  repointed by STEP 1 at `<project-dir>/.channel-server/…`, a copy it deploys there** — any
  previous copy is left on disk untouched and is no longer what the session runs; delete it
  only once the seat is certified. ⚠ `/mcp reconnect` is **not** a verified substitute: it is
  reported (roundtable #420) to fail on a live seat while the previous channel server still
  holds the port. That report has not been reproduced here, so this page claims neither
  that it works nor that it always fails — restart the session.

## Where the steps live

`php artisan bridge:provision-tools --agent=<name>` prints the **BOARD-TOOLS SETUP
PACKET** for that agent: five numbered steps, each marked with the actor who runs it, with
this install's own account, artisan path, script path, storage path and git ref already
filled in. Re-run it with `--host-a=<host>` and then with `--pubkey-from=<path>` as those
values become known; the packet mutates nothing, so re-running it is free.

STEP 3 is emitted inside the framework's `USER ACTION REQUIRED` banner. **That banner is
the handoff surface** — it is the shape an agent's human already scans for, and it is
where the pin stops being an agent's work.

## Same-box

Both legs on one machine (the agent's seat and the bridge share the box, each as its own
OS user) collapse into a single root-run wrapper — see
[`docs/board-tools.md § Same-box SSH enablement — the one-shot wrapper (card 5090)`](board-tools.md#same-box-ssh-enablement--the-one-shot-wrapper-card-5090).
The packet prints a pointer to it too.

## Not automated, and why

- **The bridge may not read the seat's files (DL-229).** An account may only read its own,
  so the bridge cannot generate the seat's key, inspect its `.mcp.json`, or verify its half
  by looking. The seat reports **by calling**; that is what `bridge:check`'s
  `seat_side_unreported` state means, and why `--probe-tools` / `--probe-tools-ssh` from
  the bridge box are not the seat's proof.
- **The pin is not computed, it is decided.** See the security statement.
- **The written path is the account's DEFAULT `~/.ssh/authorized_keys`.** `--role a` prints
  the path it wrote; it does **not** resolve sshd's `AuthorizedKeysFile`, and ⛔ **it has no
  flag that redirects the write.** On an install that relocated the file, the two things an
  operator can do are: move the written line **by hand** into the file `AuthorizedKeysFile`
  names, or point `AuthorizedKeysFile` back at the default and re-run. ⚠ `bridge:check`
  resolves the relocated path — but only when it runs **as root** (it reads sshd's
  Match-resolved effective config); unprivileged, it reads the same default this tool wrote
  and reports the pinned line `unvalidated` rather than confirmed.

## For the coord plugin

An agent-fleet coordination layer wiring a new impl seat should point at **this page** and
then run the packet, rather than restating the steps: a second copy of a five-step exchange
is a second thing to keep in sync with the command that generates it.
