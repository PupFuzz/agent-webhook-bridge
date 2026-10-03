<?php

namespace App\Bridge\Writeback;

/**
 * WHICH source gave a repo its GitHub token, or was the one that failed to — in
 * {@see GitHubTokenResolver}'s precedence order (DL-456). `bridge:check` names it per repo, and a
 * remedy depends on it: an override is fixed in `writeback.json`, a store key in the coord
 * credential store, the single file at its path.
 */
enum TokenSource
{
    /** The repo's own `write_token_path` in `writeback.json`. */
    case WriteTokenPath;

    /** The coord credential store: `[git-credential-map]` → `[github] <key>_file`. */
    case Store;

    /** The single file: `providers.github.token_path`, else `<secret_dir>/github/token`. */
    case TokenFile;

    /** `GH_TOKEN`, consulted only by the CLI callers that ask for it. */
    case Ambient;
}
