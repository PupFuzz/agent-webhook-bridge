<?php

use App\Bridge\Support\BoolEnv;
use App\Bridge\Support\CsvEnv;

return [

    /*
    |--------------------------------------------------------------------------
    | Install directories
    |--------------------------------------------------------------------------
    |
    | BRIDGE_DIR is the one base path for per-agent YAMLs + shared-identities.json
    | + per-(provider, scope) HMAC secrets + per-(provider) API tokens. config_dir
    | and secret_dir default to it; override either only when they live elsewhere.
    | All absolute, outside the repo. install_suffix is the cross-DSN safety
    | marker (-prod / -dev).
    |
    */

    'config_dir' => env('BRIDGE_CONFIG_DIR') ?: env('BRIDGE_DIR'),

    'secret_dir' => env('BRIDGE_SECRET_DIR') ?: env('BRIDGE_DIR'),

    'install_suffix' => env('BRIDGE_INSTALL_SUFFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | On/off settings the bridge could not read (card#11029)
    |--------------------------------------------------------------------------
    |
    | Every on/off `.env` key in config/ is read by BoolEnv::get(): true/1/yes/on,
    | false/0/no/off/empty, case-insensitive. Any other value runs as that setting's
    | default, and is recorded here as KEY => value so `bridge:check` can fail
    | naming it. A config value rather than a check-time `.env` read, so a cached
    | config reports the value it was cached with.
    |
    */

    'unreadable_flags' => BoolEnv::unreadable(),

    /*
    |--------------------------------------------------------------------------
    | Per-install endpoints
    |--------------------------------------------------------------------------
    |
    | Identical for every agent on one install, so they live here, not in each
    | per-agent YAML. receiver_base_url is this bridge's public webhook URL (used
    | by bridge:provision to register the callback). providers.<name>.api_base_url
    | is the upstream API base for providers the bridge calls (only kanban is
    | API-provisioned today; github's API base is constant and only relevant when
    | a github adapter needs it). The kanban base is SECRET-BEARING (writeback
    | bearer token + provision-time HMAC secret) — it must be https; cleartext
    | http is rejected at every consumer except for loopback hosts (DL-175).
    |
    */

    'receiver_base_url' => env('BRIDGE_RECEIVER_BASE_URL'),

    'providers' => [
        'kanban' => ['api_base_url' => env('BRIDGE_KANBAN_API_BASE_URL')],
        'github' => [
            'api_base_url' => env('BRIDGE_GITHUB_API_BASE_URL', 'https://api.github.com'),
            // Optional explicit path to the GitHub read token (DL-184). Absent →
            // the conventional <secret_dir>/github/token, with an ambient
            // GH_TOKEN fallback. Set this to reuse a centralized credential
            // (e.g. ~/.config/coord/github-pat) without a per-install symlink;
            // when set it is AUTHORITATIVE (no GH_TOKEN fallback) so a wrong path
            // fails loud instead of silently resolving a different credential.
            'token_path' => env('BRIDGE_GITHUB_TOKEN_PATH'),
            // Store-native resolution (DL-185): when no explicit token file is
            // placed, bridge:reconcile resolves a per-repo least-privilege PAT from
            // the coordination store via this helper (git wire-format on
            // stdin/stdout), keyed on the store's [git-credential-map]. Default is
            // the framework helper name (PATH-resolved); an absolute path is used
            // as-is; empty disables the store leg (falls back to GH_TOKEN).
            'credential_helper' => env('BRIDGE_GITHUB_CREDENTIAL_HELPER', 'git-credential-coord'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Writeback correlation mode (DL-029; default 'ref' since DL-031)
    |--------------------------------------------------------------------------
    | How the card-move writeback finds the tracking card(s) for a PR:
    |   'ref'  — one indexed `GET /boards/{b}/tasks/by-ref.json` per key (kanban
    |            DL-147/148). O(1), no paging. THE DEFAULT. Requires the kanban
    |            instance to expose by-ref (v0.17.2+) AND its
    |            task_external_references to be backfilled.
    |   'scan' — download the board and digit-match payload.dl_number/pr_number
    |            client-side (the legacy fallback; works against any kanban,
    |            incl. one that predates by-ref). Set BRIDGE_WRITEBACK_CORRELATION=scan
    |            for backwards compatibility / an un-backfilled kanban.
    | `bridge:check` probes by-ref reachability in 'ref' mode and warns loudly if
    | the kanban can't serve it (so a wrong default surfaces before traffic).
    | Both modes correlate to ALL matching cards (a PR/DL can track several — DL-148).
    */
    'writeback' => [
        'correlation' => env('BRIDGE_WRITEBACK_CORRELATION', 'ref'),
    ],

    /*
    |--------------------------------------------------------------------------
    | The coordination project's coordination.config.json (DL-200, DL-450)
    |--------------------------------------------------------------------------
    |
    | An ABSOLUTE path, set per install in .env. REQUIRED since card#11172 / DL-450:
    | the coord roster in this file is the ONE store of each agent's kanban user id,
    | and the bridge reads it AT RUNTIME — `board_take_card` (and its start form)
    | writes the calling seat's id from it, `board_correct_card` compares against it,
    | and every kanban event is attributed and echo-suppressed by it. Unset, or
    | unreadable by the PHP-FPM user, the take refuses as a named install fault and a
    | kanban delivery that needs attribution answers 5xx; `bridge:check` FAILs on an
    | unset or relative value. App\Bridge\Support\CoordConfigFile is the one reader.
    |
    | ⚠ THE RUNTIME READS THIS SETTING ONLY. The ambient $COORD_CONFIG is not read
    | here and never reaches the receiver: PHP-FPM does not inherit it, and
    | `php artisan optimize` would freeze whatever the DEPLOYING shell had. The
    | `bridge:check` cross-config compares of the writeback legs still fall back to
    | the ambient variable at their CLI read-site (CoordConfigPath), which is
    | cache-immune; the roster leg does not, because it measures what the runtime reads.
    |
    | Two installs on one host (-prod / -dev) set it separately, which is what lets
    | them point at different coordination projects.
    |
    */

    'coord_config_path' => env('BRIDGE_COORD_CONFIG_PATH'),

    /*
    |--------------------------------------------------------------------------
    | protocol:invalid on an unattributable coordination comment (card#10218, DL-408)
    |--------------------------------------------------------------------------
    |
    | The repos (`owner/name`, case-insensitive, comma-separated) on which this
    | install ADDS the `protocol:invalid` label to an issue or pull request when
    | CoordinationClassifier's coord-message family could not attribute a comment
    | created on it: no scope_author_map entry, no body `FROM:` line, and no
    | registry name for the sender. EMPTY BY DEFAULT, and empty writes nothing and
    | changes nothing: it is an outward write onto repos this install may only be
    | receiving events from, so each install names them. Add-only; the label is
    | never removed here. The write uses the receiver's placed GitHub token file
    | only, which needs Issues or Pull requests WRITE on each listed repo. A
    | failure is one logged warning and never changes routing. docs/writeback.md.
    |
    */

    'protocol_invalid_label' => [
        'repos' => CsvEnv::parse((string) env('BRIDGE_PROTOCOL_INVALID_LABEL_REPOS', '')),
    ],

    /*
    |--------------------------------------------------------------------------
    | Retention (DL-199) — event-gated, after-response, bounded
    |--------------------------------------------------------------------------
    |
    | DL-012 shipped `bridge:prune` and scheduled it NOWHERE: across three installs
    | it had never run once, and the append-only stores grew for ~45 days. So
    | retention runs off the inbound webhook itself — webhook_events grows ONLY on
    | arrival, and the gate is evaluated ON arrival, so the creator IS the
    | gate-evaluator and a silent install (which accrues nothing) needs no prune.
    | That removes the cron exception rather than adding a daemon.
    |
    | ⚠ `enabled` defaults TRUE: an upgrade starts pruning without operator action.
    | That is deliberate (a default nobody sets is exactly why DL-012 never ran) —
    | set BRIDGE_RETENTION_ENABLED=false to opt out. See docs/CHANGELOG.md.
    |
    | The windows are STRINGS in the same vocabulary as the `bridge:prune` options
    | ("30d" / "30"), parsed by the one RetentionService guard, so a config window
    | and a CLI window cannot diverge. An unparseable window prunes NOTHING (a
    | permissive fallback here would mean deleting on a fat-fingered value);
    | `bridge:check` reports it at preflight.
    |
    | null_payloads_older_than defaults 7d ⇒ that leg is ON. It is NOT an optional
    | space optimization: payloads are ~95% of this store's bytes, and only REPLAY
    | needs them — only recently. Two installs measured it independently (rt#380):
    | 894 MB of a 1.2 GB store on one, 369 MB of 18,931 rows on the other, both
    | under a retention that was working correctly the whole time. Neither install
    | could discover that without going and running SUM(LENGTH(payload)), which
    | nobody does unprompted — which is exactly the DL-199 argument for `enabled`
    | defaulting TRUE, one level up: a default nobody sets is why DL-012 never ran.
    |
    | ⛔ THIS IS THE PAYLOAD WINDOW, NOT THE ROW WINDOW, and they must not be
    | conflated. Shortening `older_than` to match saves ~16 MB and loses 14% of
    | distinct event types — including gaps `bridge:check` currently REPORTS, which
    | would then read as fixed rather than lost (rt#380, measured). Payload window
    | short; row window long.
    |
    | The right value is DETECTION LATENCY + RESPONSE TIME, not a guess at replay
    | depth. 7d covers a Friday-evening miss found by a Monday reconcile; 3d is
    | exactly the window that fails that case. A slower detector wants more.
    |
    | ⚠ THIS KEY IS THE REPLAY WINDOW IN FACT, NOT ONLY BY INTENT: `bridge:replay`
    | REFUSES an event whose payload this leg nulled (it cannot reconstruct one, and
    | dispatching the empty payload in its place stages a FABRICATED intent to the
    | agent's durable inbox). Changing it changes what is recoverable. The upgrade
    | and opt-out mechanics — no grace period, and a .env edit is inert under
    | `config:cache` — are owned by CLAUDE_DEPLOYMENT.md and docs/config-schema.md.
    |
    | interval — seconds between passes in the drained steady state (default 24h),
    | so at most one request per day pays anything at all. batch — max rows one
    | pass touches per leg; while a backlog remains the gate keeps draining on
    | successive receives instead of waiting out the interval (that is what makes a
    | 20k-row backlog drain in hours rather than 40 days), so `interval` governs the
    | CLEAN steady state and `batch` bounds any single request.
    |
    */

    'retention' => [
        'enabled' => BoolEnv::get('BRIDGE_RETENTION_ENABLED', true),
        'interval' => (int) env('BRIDGE_RETENTION_INTERVAL', 86400),
        // ⚠ The windows are deliberately NOT cast. `env()` coerces the literal
        // `true` to a BOOL, and `(string) true` is `'1'` — which parses as a valid
        // ONE-DAY window and silently deletes 29 days more than intended. A bool is
        // plausible here precisely because the sibling key above IS one. Uncast, a
        // non-string reaches RetentionConfig and is refused as a type error.
        'older_than' => env('BRIDGE_RETENTION_OLDER_THAN', '30d'),
        'null_payloads_older_than' => env('BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN', '7d'),
        'batch' => (int) env('BRIDGE_RETENTION_BATCH', 500),
    ],

    /*
    |--------------------------------------------------------------------------
    | PM standup digest (DL-306) — ON by default (DL-441), event-gated like retention
    |--------------------------------------------------------------------------
    |
    | ⭐ ON UNLESS DECLINED (card#10918 / DL-441, amending DL-306's opt-in): new
    | functionality ships enabled and names its missing setup. With no `agent` it
    | pushes nothing, logs once a day, and `bridge:check` warns naming
    | BRIDGE_STANDUP_AGENT; BRIDGE_STANDUP_ENABLED=false declines the digest.
    |
    | A periodic fleet-snapshot push to one seat (the PM), carrying ONLY facts the
    | bridge can derive from its own stores. It rides the SAME event gate retention
    | does (DL-199) rather than a cron: this design has no daemon, and adding one for
    | a digest would re-open the DL-012 exception. ⚠ CONSEQUENCE, stated because it
    | changes what the digest means: the pass runs on the first inbound webhook AFTER
    | `interval` has elapsed, so an install receiving nothing pushes nothing. That is
    | a delivery cadence, not a wall clock. ⭐ SINCE DL-325 THE WALL CLOCK IS A ROW,
    | not a second crontab line: the periodic-job registry ships a `standup_digest`
    | handler driving THIS SAME pass from `bridge:tick`, so an install that adopted
    | the one tick gets the digest on a clock and still pushes at most one per
    | `interval` (both ingresses share the marker). `bridge:standup` remains the
    | manual entry point; scheduling it separately still works and is still
    | idempotent, but it is the shape the registry exists to make unnecessary.
    |
    | ⛔ THE DIGEST MAY CARRY ONLY WHAT THE BRIDGE MEASURES. It knows DELIVERY, not
    | ACTIVITY: it can say "I pushed an event to this seat at T"; it cannot say the
    | seat read it, acted, or is mid-turn. So there is no `last_activity`, no
    | context-%, and no idle/stuck predicate here, and there is no adaptive cadence
    | keyed on one — a field the bridge cannot source is ABSENT from the payload, never
    | zero-filled or "unknown"-stringed. A digest that prints a plausible value it did
    | not measure teaches its reader to trust a number nothing stands behind.
    |
    | agent — the seat the digest is pushed to, by per-agent YAML name. Its own
    | `channel` block is the endpoint (and its `channel.auth.token_path` the bearer),
    | so the push reuses the `channel_push` handler rather than minting a second
    | transport. Unset ⇒ the digest is not set up and pushes nothing.
    |
    */

    'standup' => [
        'enabled' => BoolEnv::get('BRIDGE_STANDUP_ENABLED', true),
        // ⭐ NULL MEANS "ON BY DEFAULT" (card#10918 / DL-441): `env()` with no default returns
        // null when the key is unset, and otherwise a bool for `true`/`false` or the raw string
        // for any other spelling (`1`, `yes`, `on`). `StandupConfig` reads null-vs-anything to
        // tell an install that never touched the key (the NOT-SET-UP state) from one that
        // explicitly enabled the digest and still left the recipient unset (MISCONFIGURED).
        'enabled_explicit' => env('BRIDGE_STANDUP_ENABLED'),
        'agent' => env('BRIDGE_STANDUP_AGENT'),
        'interval' => (int) env('BRIDGE_STANDUP_INTERVAL', 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Idle-with-pending-work nudge (DL-380) — ON by default (DL-441), a periodic-job handler
    |--------------------------------------------------------------------------
    |
    | The `idle_nudge` job handler pushes ONE nudge at a seat that has sat idle past its
    | horizon with work waiting — judged from the seat's own offer record where its YAML
    | declares `idle_nudge.seat_record` (DL-424), otherwise from Mezzanine's fleet snapshot
    | and the intents pushed at it since it went idle. Read-and-alert only. ENABLED by
    | default (card#10918 / DL-441); it runs once an `idle_nudge` instance is inserted
    | (`bridge:jobs add`), and until then `bridge:check` warns naming that step.
    | BRIDGE_IDLE_NUDGE_ENABLED=false declines it. docs/periodic-jobs.md.
    |
    | ⛔ The numbers are deliberately NOT cast: a value outside its bound is refused,
    | never clamped, and a cast would turn `ten` into 0 before anything could say so.
    |
    | Mezzanine is read only for an agent that declares no `idle_nudge.seat_record` and sets
    | `channel.route_intents: true`; the keys below bind only then (DL-424).
    |
    | install — REQUIRED when Mezzanine is: the Mezzanine install id this bridge serves. The
    | fleet token reads every install. ⚠ One bridge per (install, agent name): two
    | bridges declaring the same agent names against one install each nudge.
    | token_path — a FILE holding the fleet_read token (0600), never the value.
    |
    */

    'idle_nudge' => [
        'enabled' => BoolEnv::get('BRIDGE_IDLE_NUDGE_ENABLED', true),
        // ⭐ NULL MEANS "ON BY DEFAULT" (card#10918 / DL-441) — the same tri-state as
        // `standup.enabled_explicit`, read by `IdleNudgeConfig` so a required-but-unset
        // Mezzanine key WARNS (NOT SET UP) on a default install and FAILS (MISCONFIGURED) on one
        // that explicitly enabled the nudge (in any spelling) and stopped short of setting up
        // the keys it turned the feature on for.
        'enabled_explicit' => env('BRIDGE_IDLE_NUDGE_ENABLED'),
        'base_url' => env('BRIDGE_IDLE_NUDGE_BASE_URL'),
        'token_path' => env('BRIDGE_IDLE_NUDGE_TOKEN_PATH'),
        'install' => env('BRIDGE_IDLE_NUDGE_INSTALL'),
        'timeout' => env('BRIDGE_IDLE_NUDGE_TIMEOUT', 5),
        'default_after' => env('BRIDGE_IDLE_NUDGE_DEFAULT_AFTER', 1800),
    ],

    /*
    |--------------------------------------------------------------------------
    | Periodic-job registry (DL-325) — jobs are DATA; the tick is OPT-IN
    |--------------------------------------------------------------------------
    |
    | ⛔ READ THIS BEFORE ADDING A JOB. A periodic job is the LAST RESORT in this
    | design. The bridge's first answer to "this needs to happen regularly" is the
    | AFTER-RESPONSE EVENT GATE, because the thing that creates the work is usually
    | the thing that can evaluate the gate (DL-199's symmetry argument: webhook_events
    | grows only on arrival, and the gate is evaluated on arrival). Reach for a job
    | only when no such symmetry exists — and say why, in one sentence, at insert
    | time: `justification` is a REQUIRED DOCUMENTATION SLOT — NOT a gate — and every
    | enumeration prints it. Nothing judges the answer: the insert refuses an empty one
    | on length and filters nothing else, so a stored justification means somebody wrote
    | a reason and never that anything checked it. The full decision order is in
    | docs/periodic-jobs.md.
    |
    | WHAT THE REGISTRY IS. One row per job INSTANCE, carrying
    | {name, handler, interval, owner, docs-ref, justification, enabled}. The HANDLER
    | is code — a job may only reference a handler that exists in this build, so what
    | a job CAN DO is fixed at code-review time. INSTANCES ARE FREE: any code path may
    | insert or remove one at runtime, and the crontab line never changes.
    | `bridge:jobs` enumerates the whole periodic population on demand — the audit
    | surface no crontab sweep across N accounts could give.
    |
    | ⚑ TWO INGRESSES, AND ADOPTING THE SECOND IS OPT-IN. The registry runs from the
    | inbound webhook's after-response gate (exactly DL-199's shape) on every install,
    | so an install that adds no crontab line behaves as it does today. An install that
    | ALSO adds one line — `php artisan bridge:tick`, 5/10/15-minute class, under the
    | seat-owner account and never root — additionally gets periodic work AT ZERO
    | TRAFFIC, which is the DL-306 dead end the event gate cannot close by itself
    | ("an install receiving nothing pushes nothing"). The two cover different blind
    | spots: the gate covers the busy install, the tick covers the silent one.
    |
    | enabled — the registry as a whole. TRUE by default; with no rows it costs one
    | indexed query per `min_pass_interval` on delivery and does nothing else. Set
    | BRIDGE_JOBS_ENABLED=false to register no callback at all.
    |
    | min_pass_interval — the floor between passes, SHARED by both ingresses (the
    | event gate is evaluated on every delivery). Default 60s; a 5/10/15-minute tick
    | is never affected by it.
    |
    | max_per_pass — the bound. At most this many instances run per pass, oldest-due
    | first; a backlog drains across passes. Never unbounded: on the event ingress
    | this runs inside an FPM worker after the response, and DL-001's latency bet is
    | what an unbounded pass spends.
    |
    | ⭐ disarmed_mutators — THE PER-HANDLER KILL SWITCH. A handler declaring the
    | state-mutating capability is ARMED BY DEFAULT (card#10918 / DL-441, amending
    | DL-325's opt-in arming): naming it here disarms it, and it is then refused at
    | insert AND at run, loudly, with the row recording `refused` rather than a silent
    | skip. Read-and-alert handlers are never disarmed here — stop one by disabling its
    | instance (`bridge:jobs disable`). Comma-separated handler names; `bridge:check`
    | warns on a name that is not a state-mutating handler in this build, because that
    | entry switches nothing off.
    |
    | ⭐ owed_write_retry_disabled — `owed_write_retry`'s own named kill switch
    | (card#10849 / DL-440), kept beside the list: `true`
    | (`BRIDGE_OWED_WRITE_RETRY_DISABLED=true`) disarms it exactly as naming it in
    | `disarmed_mutators` does.
    |
    | ⛔ armed_mutators (`BRIDGE_JOBS_ARMED_MUTATORS`) is RETIRED: it was DL-325's
    | opt-in list and arms nothing since DL-441. It is read only so `bridge:check` can
    | say a set value has no effect.
    |
    | ⭐ tick_expected_every — DEATH IS THE ALARM, and this is the declaration that
    | arms it. Set it to the crontab line's interval in seconds (600 for a ten-minute crontab line). The
    | bridge records the last tick it received; `bridge:jobs --assert-tick` and
    | `bridge:check` compare the record against THIS number — the install's own
    | declaration, never a fleet-wide constant, because only this install knows what
    | its crontab says. UNSET (the default) means the tick was not adopted, and then
    | no absence of a tick is ever reported as a fault. An ABSENT record reads as
    | UNMEASURED, never as death.
    | ⛔ DECLARING IT IS HALF THE ALARM. A horizon nothing ever asserts is a dead alarm
    | that READS AS COVERAGE to whoever audits this file, so `bridge:jobs --assert-tick`
    | records that it ran and `bridge:check` warns while a declared horizon has never
    | been asserted here. Wire the assert into a session-start hook; an install that
    | declares nothing is never asked to.
    |
    */

    'jobs' => [
        'enabled' => BoolEnv::get('BRIDGE_JOBS_ENABLED', true),
        'min_pass_interval' => (int) env('BRIDGE_JOBS_MIN_PASS_INTERVAL', 60),
        'max_per_pass' => (int) env('BRIDGE_JOBS_MAX_PER_PASS', 3),
        'disarmed_mutators' => env('BRIDGE_JOBS_DISARMED_MUTATORS', ''),
        'armed_mutators' => env('BRIDGE_JOBS_ARMED_MUTATORS', ''),
        'owed_write_retry_disabled' => BoolEnv::get('BRIDGE_OWED_WRITE_RETRY_DISABLED', false),
        // ⚠ Deliberately NOT cast: a null must stay a null. `(int) null` is 0, which
        // this reads back as "declared, zero seconds" — an install that never adopted
        // the tick would then be judged against a horizon it never set.
        'tick_expected_every' => env('BRIDGE_JOBS_TICK_EXPECTED_EVERY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Board tools — client-half freshness (DL-313)
    |--------------------------------------------------------------------------
    |
    | Every board-tools plane in `bridge:check` observes the BRIDGE side of the
    | door. The CALLING SEAT's half — its keypair, known_hosts, .mcp.json entries
    | and deployed channel server — lives in files the bridge may not read (an
    | account may only read its own), so the seat REPORTS BY CALLING: a successful
    | board-tools call stamps `board_tools_client_calls`, and the check reports the
    | stamp's AGE. ⚠ The row names the agent the door opened FOR, not the caller —
    | `--probe-tools`, `--self-cert` and a hand-run `bridge:tools-call` stamp it too,
    | and the leg's ok line says so.
    |
    | client_half_ttl is how old that stamp may be and still read as CURRENT, in
    | seconds (default 7 days). ⚠ It is a display threshold, nothing else: the age
    | is printed on the ok line either way, so an operator judges the number rather
    | than the boolean, and past the TTL the leg reports `unvalidated` — never
    | `fail`, never `warn`. An UNREPORTED seat is NOT an unwired seat; the bridge
    | cannot tell never-wired from merely-idle, and the leg says so in its own text.
    |
    */

    'board_tools' => [
        'client_half_ttl' => (int) env('BRIDGE_BOARD_TOOLS_CLIENT_HALF_TTL', 7 * 86400),
    ],

    /*
    |--------------------------------------------------------------------------
    | Channel-server client pack (DL-430)
    |--------------------------------------------------------------------------
    |
    | The GitHub repo whose release `v<VERSION>` carries this bridge's client
    | pack. `bridge:client-pack:install` reads it with this install's GitHub read
    | token and publishes the pack under `<state_dir>/client-packs/`, which the
    | client-update door serves to seats. Point it at your fork if you release
    | from one.
    |
    */

    'client_pack' => [
        'repo' => env('BRIDGE_CLIENT_PACK_REPO', 'PupFuzz/agent-webhook-bridge'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Runtime-state directory
    |--------------------------------------------------------------------------
    |
    | Where inbox.jsonl / inbox-<agent>.jsonl / seen cursors / handler logs
    | live. Defaults to config_dir/state (v0.11.x layout). Point it OUTSIDE the
    | secret-holding config_dir (which is 0700) when a co-located different-OS-
    | user agent must read its own per-agent inbox via the group convention —
    | the config_dir can't be group-traversable without exposing secrets.
    |
    */

    'state_dir' => env('BRIDGE_STATE_DIR'),

    /*
    |--------------------------------------------------------------------------
    | Inbox surfacing layout (multi-agent single install)
    |--------------------------------------------------------------------------
    |
    | shared    — one inbox.jsonl for all agents (default; single-agent and
    |             pre-v0.16 behavior). Every staged line carries an `agent`
    |             field regardless.
    | per-agent — one inbox-<agent>.jsonl per serving agent; each session reads
    |             only its own file with its own seen cursor.
    | both      — write both (shared for a global tail + per-agent for clean
    |             per-session views).
    |
    | default_agent: when set, a bare `bridge:inbox` (no --agent) surfaces this
    | agent — for an install with one primary agent that still wants the
    | per-agent file/cursor. file_mode + group are applied to per-agent inbox +
    | seen files so a co-located OS-user agent in `group` can read its own inbox
    | (the cross-user convention; see docs/multi-agent.md).
    |
    */

    'inbox_layout' => env('BRIDGE_INBOX_LAYOUT', 'shared'),

    'default_agent' => env('BRIDGE_DEFAULT_AGENT'),

    'inbox_file_mode' => env('BRIDGE_INBOX_FILE_MODE', '0640'),

    'inbox_group' => env('BRIDGE_INBOX_GROUP'),

    /*
    |--------------------------------------------------------------------------
    | Receiver body-size cap
    |--------------------------------------------------------------------------
    |
    | HMAC is verified over the raw body, so an oversize invalid-signature
    | body would burn CPU before the 401. 256 KB covers every real provider
    | payload (kanban ~10 KB; GitHub push with large diffs ~50-100 KB).
    |
    */

    'max_body_bytes' => (int) env('BRIDGE_MAX_BODY_BYTES', 256 * 1024),

    /*
    |--------------------------------------------------------------------------
    | spawn_detached handler (off by default — DL-011)
    |--------------------------------------------------------------------------
    |
    | spawn_detached runs a detached child process: the highest-blast-radius
    | handler (RCE as the install user). "cmd is operator-authored" is a
    | convention, not an invariant — docs/customization.md invites custom
    | classifiers, and a passthrough one would hand an attacker the argv. So it
    | is NOT registered unless `enabled`, and even then the program (cmd[0]) must
    | be one of `allowlist` (absolute paths, comma-separated in
    | BRIDGE_SPAWN_ALLOWLIST). An empty allowlist with enabled=true runs nothing.
    | Execution is shell-free (proc_open argv + `setsid -f`), so there is no
    | shell-metacharacter surface regardless.
    |
    | ⚠ Allowlist FIXED-PURPOSE WRAPPER SCRIPTS, not an interpreter or flag-
    | flexible tool (php, bash, env, git, find, awk, ssh, …): the allowlist gates
    | only cmd[0], and the classifier controls cmd[1..], so one allowlisted
    | `php`/`git` lets attacker-supplied args run arbitrary code — reopening the
    | RCE this guards against.
    |
    */

    'spawn' => [
        'enabled' => BoolEnv::get('BRIDGE_SPAWN_ENABLED', false),
        'allowlist' => CsvEnv::parse((string) env('BRIDGE_SPAWN_ALLOWLIST', '')),
        // Absolute path to the `setsid` launcher. Null ⇒ auto-detect
        // (/usr/bin/setsid, /bin/setsid). Pinned absolute so a payload env PATH
        // can't redirect which setsid runs (allowlist bypass otherwise).
        'setsid_path' => env('BRIDGE_SPAWN_SETSID_PATH'),
    ],

    /*
    |--------------------------------------------------------------------------
    | channel_push — classifier-supplied socket constraint (DL-014)
    |--------------------------------------------------------------------------
    |
    | An agent's own `channel.socket` (operator-authored YAML) is trusted. But a
    | CUSTOM classifier can also emit a channel_push target with its own `socket`
    | path in the payload — attacker-influenced, same trust class as
    | spawn_detached's argv. Without a constraint, such a socket could point at
    | another tenant's UDS. allowed_socket_dir (BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR)
    | is the absolute prefix a classifier-supplied socket must sit under; when
    | unset, classifier-supplied sockets are refused outright (fail-closed). The
    | agent-config socket path is exempt either way.
    |
    */

    'channel' => [
        'allowed_socket_dir' => env('BRIDGE_CHANNEL_ALLOWED_SOCKET_DIR'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Global echo identities (DL-009)
    |--------------------------------------------------------------------------
    |
    | Provider actor ids whose events are NEVER a signal for ANY agent — the
    | bridge's own machine-write identities (e.g. the kanban user a future
    | card-move writeback acts as), whose resulting card_updated webhook would
    | otherwise loop back into the bridge. Unioned into every agent's echo set.
    | Comma-separated in BRIDGE_GLOBAL_ECHO_IDS.
    |
    */

    'global_echo_ids' => CsvEnv::parse((string) env('BRIDGE_GLOBAL_ECHO_IDS', '')),

];
