<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Per-agent config + secret directories
    |--------------------------------------------------------------------------
    |
    | Operator-configured paths (preserved from v0.11.x for interface
    | continuity): per-agent YAML + agents.json live under config_dir, and
    | the per-(provider, scope) HMAC secrets under secret_dir. Both are
    | absolute paths outside the repo. install_suffix is the cross-DSN
    | safety marker (-prod / -dev).
    |
    */

    'secret_dir' => env('BRIDGE_SECRET_DIR'),

    'config_dir' => env('BRIDGE_CONFIG_DIR'),

    'install_suffix' => env('BRIDGE_INSTALL_SUFFIX', ''),

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

];
