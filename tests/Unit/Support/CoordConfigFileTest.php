<?php

namespace Tests\Unit\Support;

use App\Bridge\Support\CoordConfigFile;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The one read of `coordination.config.json` (card#11172 / DL-450): which fault each input is, and
 * that the per-process cache re-reads a CHANGED file and only a changed one.
 */
class CoordConfigFileTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/coord-file-'.uniqid();
        File::ensureDirectoryExists($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_runtime_read_is_the_setting_alone(): void
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, '{"roster":[]}');
        $orig = getenv('COORD_CONFIG');
        putenv('COORD_CONFIG='.$path);
        try {
            config(['bridge.coord_config_path' => null]);
            $this->assertSame(CoordConfigFile::UNSET, CoordConfigFile::configured()->fault, 'the ambient variable is not read');

            config(['bridge.coord_config_path' => $path]);
            $this->assertTrue(CoordConfigFile::configured()->readable());
        } finally {
            $orig === false ? putenv('COORD_CONFIG') : putenv('COORD_CONFIG='.$orig);
        }
    }

    public function test_each_fault_is_named(): void
    {
        File::put($this->dir.'/bad.json', '[1, 2');
        File::put($this->dir.'/scalar.json', '7');
        File::put($this->dir.'/target.json', '{"roster":[]}');
        symlink($this->dir.'/target.json', $this->dir.'/link.json');

        $this->assertSame(CoordConfigFile::UNSET, CoordConfigFile::at(null)->fault);
        $this->assertSame(CoordConfigFile::NOT_ABSOLUTE, CoordConfigFile::at('relative/coordination.config.json')->fault);
        $this->assertSame(CoordConfigFile::UNREADABLE, CoordConfigFile::at($this->dir.'/absent.json')->fault);
        $this->assertSame(CoordConfigFile::MALFORMED, CoordConfigFile::at($this->dir.'/bad.json')->fault);
        $this->assertSame(CoordConfigFile::MALFORMED, CoordConfigFile::at($this->dir.'/scalar.json')->fault);
        $this->assertSame(CoordConfigFile::UNREADABLE, CoordConfigFile::at($this->dir.'/link.json')->fault, 'a symlink is refused, as UntrustedPathContents refuses one');
        $this->assertNull(CoordConfigFile::at($this->dir.'/target.json')->fault);

        $this->assertStringContainsString('BRIDGE_COORD_CONFIG_PATH is not set', CoordConfigFile::at(null)->faultClause());
        $this->assertStringContainsString($this->dir.'/absent.json', CoordConfigFile::at($this->dir.'/absent.json')->faultClause());
    }

    public function test_an_unchanged_file_is_answered_from_the_cache(): void
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, '{"roster":[{"name":"a"}]}');

        $this->assertSame(CoordConfigFile::at($path), CoordConfigFile::at($path));
    }

    /** A replace through a new inode — how the coord tooling and editors write — is re-read. */
    public function test_a_file_replaced_by_rename_is_re_read(): void
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, '{"v":1}');
        $this->assertSame(['v' => 1], CoordConfigFile::at($path)->config());

        File::put($path.'.tmp', '{"v":2}');
        rename($path.'.tmp', $path);

        $this->assertSame(['v' => 2], CoordConfigFile::at($path)->config());
    }

    /**
     * An IN-PLACE rewrite in the same second is caught by its size; the bound the class states is
     * only a same-size, same-second, same-inode rewrite.
     */
    public function test_a_file_rewritten_in_place_to_a_different_size_is_re_read(): void
    {
        $path = $this->dir.'/coordination.config.json';
        File::put($path, '{"v":1}');
        $this->assertSame(['v' => 1], CoordConfigFile::at($path)->config());

        File::put($path, '{"v":12}');

        $this->assertSame(['v' => 12], CoordConfigFile::at($path)->config());
    }

    /** A file that APPEARS after a failed read is read: the absent answer is not cached past it. */
    public function test_a_file_created_after_an_absent_read_is_read(): void
    {
        $path = $this->dir.'/coordination.config.json';
        $this->assertSame(CoordConfigFile::UNREADABLE, CoordConfigFile::at($path)->fault);

        File::put($path, '{"v":3}');

        $this->assertSame(['v' => 3], CoordConfigFile::at($path)->config());
    }
}
