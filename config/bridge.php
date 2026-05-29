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
