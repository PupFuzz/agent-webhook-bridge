<?php

namespace Tests\Unit\Bridge\Check\Checks;

use App\Bridge\Check\CheckContext;
use App\Bridge\Check\Checks\InstallFlagValuesCheck;
use App\Bridge\Check\Silence;
use App\Bridge\Support\Finding;
use App\Bridge\Support\Severity;
use Tests\Support\MaterializesChecks;
use Tests\TestCase;

/**
 * The `install.flag_values` leg (card#11029): one `fail` per on/off setting whose value the
 * bridge could not read, naming the key and the value. Every golden install pins the record
 * empty, so the failing lines and the stale-cache `unvalidated` are asserted here. The record
 * itself — what `config/bridge.php` stores, and that a cached config carries it — is
 * `BoolEnvFlagsTest`'s and `BoolEnvConfigCacheTest`'s subject.
 */
class InstallFlagValuesCheckTest extends TestCase
{
    use MaterializesChecks;

    public function test_each_unreadable_setting_fails_naming_its_key_and_value(): void
    {
        config(['bridge.unreadable_flags' => ['APP_DEBUG' => 'maybe', 'BRIDGE_SPAWN_ENABLED' => 'nope']]);

        $findings = $this->findingsFrom();

        $this->assertCount(2, $findings);
        $this->assertSame([Severity::Fail, Severity::Fail], array_map(static fn (Finding $f): Severity => $f->severity, $findings));
        $this->assertStringStartsWith("APP_DEBUG='maybe' is not an on/off value", $findings[0]->message);
        $this->assertStringStartsWith("BRIDGE_SPAWN_ENABLED='nope' is not an on/off value", $findings[1]->message);
        $this->assertStringContainsString("running with that setting's default", $findings[1]->message);
    }

    public function test_a_control_byte_in_the_value_is_escaped(): void
    {
        config(['bridge.unreadable_flags' => ['BRIDGE_SPAWN_ENABLED' => "no\x1B[31mpe"]]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertStringContainsString("BRIDGE_SPAWN_ENABLED='no\\x1B[31mpe'", $findings[0]->message);
        $this->assertStringNotContainsString("\x1B", $findings[0]->message);
    }

    public function test_an_empty_record_is_a_declared_silence(): void
    {
        config(['bridge.unreadable_flags' => []]);

        $out = iterator_to_array((new InstallFlagValuesCheck)->run(new CheckContext), false);

        $this->assertCount(1, $out);
        $this->assertInstanceOf(Silence::class, $out[0]);
    }

    public function test_a_config_without_the_record_is_unvalidated_not_clean(): void
    {
        config(['bridge.unreadable_flags' => null]);

        $findings = $this->findingsFrom();

        $this->assertCount(1, $findings);
        $this->assertSame(Severity::Unvalidated, $findings[0]->severity);
        $this->assertStringContainsString('config:cache', $findings[0]->message);
    }

    /** @return list<Finding> */
    private function findingsFrom(): array
    {
        return $this->findingsOf(new InstallFlagValuesCheck, new CheckContext);
    }
}
