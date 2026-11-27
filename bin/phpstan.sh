#!/usr/bin/env bash
#
# The one way to run PHPStan on this repo, locally and in CI. Extra arguments
# are passed through to `phpstan analyse` (CI passes --no-progress).
#
# WHY AN EMPTY ENVIRONMENT: PHPStan compiles its DI container into
# <tmpDir>/cache/nette.configurator/Container_*.php and writes the caller's
# whole environment into that file. Run from a shell that exports a token, the
# token lands in the cache. `env -i` hands PHPStan only PATH, which it needs to
# resolve the `php` on vendor/bin/phpstan's shebang. It runs without anything
# else: the memory limit is php.ini's and the tmpDir is the config's. A
# variable added here is written into the cache with the rest.
#
# WHY -c IS FIXED HERE: phpstan-laravel.neon is the repo's only config, and its
# tmpDir keeps the cache inside the repo (gitignored). A bare `phpstan analyse`
# finds no config, caches under the system temp dir and exits 1 having printed
# nothing.
set -euo pipefail

cd "$(dirname "$0")/.."
exec env -i PATH="$PATH" vendor/bin/phpstan analyse -c phpstan-laravel.neon "$@"
