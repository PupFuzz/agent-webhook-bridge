<?php

namespace Tests\Feature\Console\Check;

use Illuminate\Support\Facades\File;
use Tests\Support\CheckGolden\PinnedHost;
use Tests\TestCase;

/**
 * {@see PinnedHost::restore()} returns the process to the values it had BEFORE the host touched
 * it — including when the immunity test perturbs the ambient values first. The golden harness
 * runs in the same PHP process as every later test, so a pinned or perturbed `PATH` that
 * outlives its test breaks every subprocess launched after it.
 */
class PinnedHostTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $before = [];

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        foreach (['PATH', 'XDG_RUNTIME_DIR', 'COORD_CONFIG', 'GH_TOKEN'] as $var) {
            $this->before[$var] = getenv($var);
        }
        $this->root = sys_get_temp_dir().'/pinned-host-'.bin2hex(random_bytes(6));
        File::ensureDirectoryExists($this->root);
    }

    protected function tearDown(): void
    {
        foreach ($this->before as $var => $value) {
            $value === false ? putenv($var) : putenv("{$var}={$value}");
        }
        File::deleteDirectory($this->root);
        parent::tearDown();
    }

    public function test_restore_after_a_perturbed_boot_returns_the_process_to_its_own_values(): void
    {
        putenv('PATH=/opt/fixture-only/bin:/usr/bin');
        putenv('GH_TOKEN');
        $host = new PinnedHost($this->root);

        $host->perturbAmbient();
        $host->apply();
        $this->assertSame($this->root.'/bin', getenv('PATH'), 'apply() did not pin PATH — the witness that there was something to restore');
        $host->restore();

        $this->assertSame('/opt/fixture-only/bin:/usr/bin', getenv('PATH'));
        $this->assertFalse(getenv('GH_TOKEN'), 'the perturbation\'s fake GH_TOKEN outlived the test');
    }

    public function test_restore_after_a_plain_boot_returns_the_process_to_its_own_values(): void
    {
        putenv('PATH=/opt/fixture-only/bin:/usr/bin');
        $host = new PinnedHost($this->root);

        $host->apply();
        $host->restore();

        $this->assertSame('/opt/fixture-only/bin:/usr/bin', getenv('PATH'));
    }
}
