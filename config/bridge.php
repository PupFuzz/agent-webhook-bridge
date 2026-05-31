<?php

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
    | a github adapter needs it).
    |
    */

    'receiver_base_url' => env('BRIDGE_RECEIVER_BASE_URL'),

    'providers' => [
        'kanban' => ['api_base_url' => env('BRIDGE_KANBAN_API_BASE_URL')],
        'github' => ['api_base_url' => env('BRIDGE_GITHUB_API_BASE_URL', 'https://api.github.com')],
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
        'allowlist' => array_values(array_filter(
            array_map('trim', explode(',', (string) env('BRIDGE_SPAWN_ALLOWLIST', ''))),
            fn (string $p) => $p !== '',
        )),
    ],

];
