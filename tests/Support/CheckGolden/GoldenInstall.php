<?php

namespace Tests\Support\CheckGolden;

use Illuminate\Support\Facades\File;

/**
 * A throwaway `bridge:check` install: a temp config+secret dir, plus the full set of
 * `bridge.*` config keys the command reads, all set EXPLICITLY (DL-242 stage 0).
 *
 * The explicit config is not ceremony. `config/bridge.php` resolves those keys through
 * `env()`, and this repo's checkout carries a real deployed `.env` — so on an operator
 * box `config('bridge.default_agent')` and `config('bridge.receiver_base_url')` are that
 * install's values, and both reach `bridge:check`'s output (the `BRIDGE_DEFAULT_AGENT`
 * leg warns about a default agent with no matching config; the per-install endpoint-URL
 * loop validates the receiver URL through `UrlValidator`). CI, with no `.env`, reads
 * null for both. That is a third class of host input beyond the falsifier's env-var
 * enumeration and the one most likely to be mistaken for a fixture property: it is
 * config, so it looks declared, but its value came from the host.
 *
 * A fixture therefore DECLARES its install rather than inheriting one.
 */
final class GoldenInstall
{
    /** config_dir and secret_dir are the same path, the single-dir layout most installs use. */
    public readonly string $root;

    public function __construct(string $name)
    {
        $this->root = sys_get_temp_dir().'/check-golden-'.$name.'-'.getmypid();
        File::deleteDirectory($this->root);
        File::ensureDirectoryExists($this->root);
    }

    /**
     * Every `bridge.*` key `bridge:check` reads, pinned to a clean baseline. Fixtures
     * override individual keys after calling this.
     */
    public function boot(): self
    {
        config([
            'bridge.config_dir' => $this->root,
            'bridge.secret_dir' => $this->root,
            'bridge.install_suffix' => '',
            'bridge.receiver_base_url' => 'https://bridge.example.com',
            'bridge.providers' => [
                'kanban' => ['api_base_url' => 'https://kanban.example.com/api/v3'],
                'github' => [
                    'api_base_url' => 'https://api.github.com',
                    'token_path' => null,
                    // A path that cannot exist: the real helper IS on PATH on an operator
                    // box, and it would resolve a live store credential mid-capture.
                    'credential_helper' => $this->root.'/no-store-helper',
                ],
            ],
            'bridge.default_agent' => null,
            'bridge.writeback.correlation' => 'ref',
            'bridge.writeback.coord_config_path' => null,
            'bridge.retention.enabled' => true,
            'bridge.retention.interval' => 86400,
            'bridge.retention.older_than' => '30d',
            'bridge.retention.null_payloads_older_than' => '7d',
            'bridge.retention.batch' => 500,
            'bridge.inbox_layout' => 'shared',
            'bridge.state_dir' => null,
        ]);

        return $this;
    }

    /** Write `<name>.yml`. The filename IS the agent name (there is no `identity.self`). */
    public function agent(string $name, string $yaml): self
    {
        File::put($this->root."/{$name}.yml", $yaml);

        return $this;
    }

    /** @param array<string, mixed> $data */
    public function json(string $relativePath, array $data): self
    {
        $path = $this->root.'/'.ltrim($relativePath, '/');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, (string) json_encode($data, JSON_PRETTY_PRINT));

        return $this;
    }

    /** A secret file with the 0600 perms the command's permission checks demand. */
    public function secret(string $relativePath, string $contents): self
    {
        $path = $this->root.'/'.ltrim($relativePath, '/');
        File::ensureDirectoryExists(dirname($path));
        File::put($path, $contents);
        chmod($path, 0o600);

        return $this;
    }

    public function path(string $relativePath = ''): string
    {
        return $relativePath === '' ? $this->root : $this->root.'/'.ltrim($relativePath, '/');
    }

    public function destroy(): void
    {
        File::deleteDirectory($this->root);
    }
}
