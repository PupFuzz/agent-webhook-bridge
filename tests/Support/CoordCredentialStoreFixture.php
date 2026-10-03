<?php

namespace Tests\Support;

use Illuminate\Support\Facades\File;

/**
 * A coord credential store (`credentials.ini`) in a temp dir, with one token FILE per key — the
 * shape the framework's `/coord:init` seeds — and the bridge pointed at it (DL-456). Tokens are
 * synthetic; nothing here reads the operator's store.
 */
final class CoordCredentialStoreFixture
{
    public function __construct(public readonly string $dir)
    {
        File::ensureDirectoryExists($dir);
    }

    /** Point the bridge at `<dir>/credentials.ini`. */
    public function use(): self
    {
        config(['bridge.coord_credentials_path' => $this->path()]);

        return $this;
    }

    public function path(): string
    {
        return $this->dir.'/credentials.ini';
    }

    /**
     * Write the store: `$map` is `[git-credential-map]` (coordinate => key), `$github` is `[github]`
     * (name => value) verbatim, `$extra` is appended as-is.
     *
     * @param  array<string, string>  $map
     * @param  array<string, string>  $github
     */
    public function write(array $map, array $github, string $extra = ''): self
    {
        $ini = "# synthetic store\n[github]\n";
        foreach ($github as $name => $value) {
            $ini .= "{$name} = {$value}\n";
        }
        $ini .= "\n[git-credential-map]\n";
        foreach ($map as $coordinate => $key) {
            $ini .= "{$coordinate} = {$key}\n";
        }
        File::put($this->path(), $ini.$extra);
        chmod($this->path(), 0o600);

        return $this;
    }

    /** Write raw store text. */
    public function raw(string $ini): self
    {
        File::put($this->path(), $ini);
        chmod($this->path(), 0o600);

        return $this;
    }

    /** A token file under the fixture dir, chmod 600 by default; returns its path. */
    public function tokenFile(string $name, string $token, int $mode = 0o600): string
    {
        $path = $this->dir.'/'.$name;
        File::put($path, $token);
        chmod($path, $mode);

        return $path;
    }
}
