<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\Checks\ReceiverPostMaxSizeCheck;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ReceiverPhpLimits;
use App\Bridge\Support\Severity;
use Illuminate\Support\Facades\File;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The web SAPI's post_max_size against bridge.max_body_bytes (card#12576): warn below the cap,
 * silent-ok at or above it and at 0 (no limit), and UNMEASURED — never `ok` — with no record.
 */
class ReceiverPostMaxSizeCheckTest extends TestCase
{
    use MaterializesChecks;

    private string $state;

    protected function setUp(): void
    {
        parent::setUp();
        $this->state = sys_get_temp_dir().'/rpms-'.uniqid();
        File::ensureDirectoryExists($this->state);
        config(['bridge.state_dir' => $this->state, 'bridge.max_body_bytes' => 1048576]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->state);
        parent::tearDown();
    }

    private function recorded(string $postMaxSize, string $sapi = 'fpm-fcgi'): void
    {
        ReceiverPhpLimits::observe($sapi, '8.5', '/etc/php/8.5/fpm/php.ini', ['post_max_size' => $postMaxSize]);
    }

    private function only(): Finding
    {
        $findings = $this->findingsOf(new ReceiverPostMaxSizeCheck);
        $this->assertCount(1, $findings);

        return $findings[0];
    }

    public function test_a_web_value_below_the_cap_warns_with_the_value_the_file_and_the_reload(): void
    {
        $this->recorded('512K');

        $f = $this->only();

        $this->assertSame(Severity::Warn, $f->severity);
        $this->assertStringContainsString('512K (524288 B)', $f->message);
        $this->assertStringContainsString('below bridge.max_body_bytes 1048576 B', $f->message);
        $this->assertStringContainsString('Raise post_max_size to at least 1M in /etc/php/8.5/fpm/php.ini', $f->message);
        $this->assertStringContainsString('/etc/php/8.5/fpm/pool.d/', $f->message);
        $this->assertStringContainsString('sudo systemctl reload php8.5-fpm', $f->message);
    }

    public function test_the_minimum_named_is_the_cap_rounded_up_to_whole_mebibytes(): void
    {
        config(['bridge.max_body_bytes' => 1048577]);
        $this->recorded('1M');

        $this->assertStringContainsString('at least 2M', $this->only()->message);
    }

    public function test_apache_mod_php_is_told_to_reload_apache(): void
    {
        $this->recorded('256K', 'apache2handler');

        $this->assertStringContainsString('sudo systemctl reload apache2', $this->only()->message);
    }

    public function test_a_web_value_at_or_above_the_cap_is_ok(): void
    {
        $this->recorded('1M');
        $this->assertSame(Severity::Ok, $this->only()->severity);

        $this->recorded('8M');
        $this->assertSame(Severity::Ok, $this->only()->severity);
    }

    public function test_zero_is_no_limit_and_ok(): void
    {
        $this->recorded('0');

        $f = $this->only();
        $this->assertSame(Severity::Ok, $f->severity);
        $this->assertStringContainsString('no limit', $f->message);
    }

    public function test_no_record_is_unmeasured_with_the_commands_and_never_ok(): void
    {
        $f = $this->only();

        $this->assertSame(Severity::Unvalidated, $f->severity);
        $this->assertStringContainsString('UNMEASURED', $f->message);
        $this->assertStringContainsString("-i | grep -E '^(Loaded Configuration File|post_max_size)'", $f->message);
        $this->assertStringContainsString('at least 1M', $f->message);
    }

    public function test_this_cli_process_never_records_itself(): void
    {
        ReceiverPhpLimits::observeThisProcess();

        $this->assertNull(ReceiverPhpLimits::read());
        $this->assertSame(Severity::Unvalidated, $this->only()->severity);
    }

    public function test_an_unreadable_size_is_unmeasured(): void
    {
        $this->recorded('lots');

        $this->assertSame(Severity::Unvalidated, $this->only()->severity);
    }
}
