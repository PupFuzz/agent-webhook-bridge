<?php

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

        /*
        | The coordination project's coordination.config.json (DL-200). Read ONLY by
        | `bridge:check`, to compare the OTHER mover's terminal_columns against this
        | bridge's coord_card_terminal_stage_id — the cross-config compare that makes
        | the move leg's config legitimate.
        |
        | CLI-ONLY, DELIBERATELY. Falls back to the ambient $COORD_CONFIG, which exists in
        | an operator's shell but NOT in the PHP-FPM environment the receiver runs under.
        | Nothing on the request path may read this: a synchronous webhook coupled to a
        | file that isn't there at runtime fails silently in the one process nobody
        | watches. Absent ⇒ the check reports CANNOT-VERIFY; it never fails the bridge.
        |
        | Two installs on one host (-prod / -dev) share ONE ambient $COORD_CONFIG, so
        | this per-install override in that install's .env is what lets them point at
        | different coordination projects.
        |
        | ⚠ The ambient $COORD_CONFIG is DELIBERATELY NOT read here. `php artisan
        | optimize` (the documented deploy step, CLAUDE_DEPLOYMENT.md) caches this file,
        | which FREEZES every env() at cache-build time — and the frozen value then wins
        | over the live one. An ambient $COORD_CONFIG baked in here would resolve to
        | whatever the DEPLOYING shell had (usually nothing), permanently, and the
        | cross-config compare would report CANNOT-VERIFY forever: shipped, running, and
        | inert — the exact failure this preflight exists to prevent. So the ambient
        | fallback is read at the CLI read-site via getenv() (see CheckCommand), which is
        | cache-immune and legitimate precisely because that read-site is CLI-only.
        */
        'coord_config_path' => env('BRIDGE_COORD_CONFIG_PATH'),
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
    | null_payloads_older_than defaults empty ⇒ that leg is OFF: it is an optional
    | space optimization for a large store, not part of the growth fix.
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
        'enabled' => (bool) env('BRIDGE_RETENTION_ENABLED', true),
        'interval' => (int) env('BRIDGE_RETENTION_INTERVAL', 86400),
        // ⚠ The windows are deliberately NOT cast. `env()` coerces the literal
        // `true` to a BOOL, and `(string) true` is `'1'` — which parses as a valid
        // ONE-DAY window and silently deletes 29 days more than intended. A bool is
        // plausible here precisely because the sibling key above IS one. Uncast, a
        // non-string reaches RetentionConfig and is refused as a type error.
        'older_than' => env('BRIDGE_RETENTION_OLDER_THAN', '30d'),
        'null_payloads_older_than' => env('BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN', ''),
        'batch' => (int) env('BRIDGE_RETENTION_BATCH', 500),
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
        'enabled' => (bool) env('BRIDGE_SPAWN_ENABLED', false),
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
