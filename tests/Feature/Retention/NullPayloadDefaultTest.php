<?php

namespace Tests\Feature\Retention;

use Tests\Support\CheckGolden\GoldenInstall;
use Tests\TestCase;

/**
 * The SHIPPED default of the payload-nulling leg (DL-315, rt#380).
 *
 * ⛔ THIS ASSERTS THE LITERAL IN `config/bridge.php`, NOT A RESOLVED VALUE. The first
 * form of this test did `require base_path('config/bridge.php')` and compared the
 * returned array — but that EVALUATES `env('BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN',
 * '7d')`, so it asserted the value in whatever environment happened to be running it,
 * and it was broken in BOTH directions:
 *
 *  - on a machine where that variable is set to `7d`, reverting the shipped default to
 *    `''` left it GREEN — the exact regression it exists to catch;
 *  - an operator who follows DL-315's own opt-out advice (`BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN=`
 *    in `.env`) got a FALSE RED from a correctly-configured checkout.
 *
 * A pin over the file's SOURCE TEXT has neither failure, and
 * {@see test_the_source_pin_does_not_move_when_the_environment_does()} proves that
 * against a live override rather than asserting it.
 *
 * The subject is still the default and not a test override: {@see GoldenInstall} pins
 * its own retention keys for determinism, so every golden fixture would keep passing if
 * the shipped default silently reverted. The whole point of rt#380 is that the leg was
 * OFF on every install that never tuned it.
 */
class NullPayloadDefaultTest extends TestCase
{
    /**
     * The env var whose presence broke the previous form of this test. Named as a
     * constant because the control leg has to set the REAL one — a stand-in would
     * prove the pin survives an unrelated variable, which nothing doubted.
     */
    private const OPT_OUT_VAR = 'BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN';

    private const PAYLOAD_WINDOW_PIN = "/'null_payloads_older_than'\s*=>\s*env\(\s*'BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN'\s*,\s*'7d'\s*\)/";

    private const ROW_WINDOW_PIN = "/'older_than'\s*=>\s*env\(\s*'BRIDGE_RETENTION_OLDER_THAN'\s*,\s*'30d'\s*\)/";

    private function configSource(): string
    {
        $source = file_get_contents(base_path('config/bridge.php'));
        $this->assertIsString($source, 'config/bridge.php is unreadable — this pin measures nothing');

        return $source;
    }

    /**
     * Exactly one site, not at-least-one: a second spelling of the same default is the
     * divergent-copy defect, and `assertMatchesRegularExpression` would be satisfied by
     * whichever copy happened to be right.
     */
    private function assertPinnedExactlyOnce(string $pattern, string $source, string $message): void
    {
        $this->assertSame(1, preg_match_all($pattern, $source), $message);
    }

    public function test_the_payload_nulling_leg_ships_on_at_seven_days(): void
    {
        $this->assertPinnedExactlyOnce(
            self::PAYLOAD_WINDOW_PIN,
            $this->configSource(),
            'config/bridge.php must ship `null_payloads_older_than => env(..., \'7d\')` at exactly one site: '
            .'two installs measured 894 MB and 369 MB of payload under a retention that was working '
            .'correctly, and neither could discover it without running SUM(LENGTH(payload)) unprompted (rt#380)',
        );
    }

    public function test_the_row_window_is_not_shortened_to_match(): void
    {
        $this->assertPinnedExactlyOnce(
            self::ROW_WINDOW_PIN,
            $this->configSource(),
            'payload window short, ROW window long — shortening the row window to match saves ~16 MB '
            .'and loses 14% of distinct event types, including gaps bridge:check REPORTS, which would '
            .'then read as fixed rather than lost (rt#380, measured)',
        );
    }

    public function test_the_source_pin_does_not_move_when_the_environment_does(): void
    {
        // THE CONTROL FOR THE CLASS DOCBLOCK'S CLAIM. Without it, "the pin is
        // environment-independent" is an argument about how regexes work; with it, the
        // environment is actually moved and the resolved value is READ BACK to prove the
        // override reached config/bridge.php at all. That read-back is the leg that makes
        // the pin's survival mean something — an override that silently did not apply
        // would leave this test green while proving nothing. It has already earned its
        // keep once: a first version set only `putenv()` and the control REDDENED under
        // an exported `BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN`, which is exactly the
        // ambient shape it exists to model (see {@see withOptOutVar()}).
        $this->withOptOutVar('90d', function (): void {
            $resolved = require base_path('config/bridge.php');
            $this->assertSame(
                '90d',
                $resolved['retention']['null_payloads_older_than'],
                'control: the environment override did not reach config/bridge.php, so this test proves '
                .'nothing about environment independence',
            );

            // Same file, same process, environment moved — the shipped literal has not.
            $this->assertPinnedExactlyOnce(
                self::PAYLOAD_WINDOW_PIN,
                $this->configSource(),
                'the source pin moved with the environment — it is asserting a resolved value again',
            );
        });

        // And the opt-out spelling an operator is actually told to use. This is the
        // direction that produced a FALSE RED under the previous form of this test.
        $this->withOptOutVar('', function (): void {
            $optedOut = require base_path('config/bridge.php');
            $this->assertSame(
                '',
                $optedOut['retention']['null_payloads_older_than'],
                'control: the documented opt-out spelling did not reach config/bridge.php',
            );
            $this->assertPinnedExactlyOnce(
                self::PAYLOAD_WINDOW_PIN,
                $this->configSource(),
                'an install that took DL-315\'s own opt-out advice must not red the shipped-default pin',
            );
        });
    }

    /**
     * Run $body with {@see self::OPT_OUT_VAR} set to $value, restoring every channel after.
     *
     * ⛔ ALL THREE CHANNELS, and that is not belt-and-braces. Laravel's env repository
     * reads `ServerConstAdapter` ($_ENV / $_SERVER) BEFORE `PutenvAdapter`, and both a
     * `.env` line and an exported shell variable land in $_SERVER — so a `putenv()`-only
     * override is SILENTLY DEFEATED by the very ambient state this control models, and
     * the leg would then assert environment independence against an environment that
     * never moved. Measured: the putenv-only version reddened its own read-back under
     * `BRIDGE_RETENTION_NULL_PAYLOADS_OLDER_THAN= vendor/bin/phpunit`.
     */
    private function withOptOutVar(string $value, callable $body): void
    {
        $priorPutenv = getenv(self::OPT_OUT_VAR);
        $priorEnv = array_key_exists(self::OPT_OUT_VAR, $_ENV) ? $_ENV[self::OPT_OUT_VAR] : null;
        $priorServer = array_key_exists(self::OPT_OUT_VAR, $_SERVER) ? $_SERVER[self::OPT_OUT_VAR] : null;

        putenv(self::OPT_OUT_VAR.'='.$value);
        $_ENV[self::OPT_OUT_VAR] = $value;
        $_SERVER[self::OPT_OUT_VAR] = $value;

        try {
            $body();
        } finally {
            if ($priorPutenv === false) {
                putenv(self::OPT_OUT_VAR);
            } else {
                putenv(self::OPT_OUT_VAR.'='.$priorPutenv);
            }
            if ($priorEnv === null) {
                unset($_ENV[self::OPT_OUT_VAR]);
            } else {
                $_ENV[self::OPT_OUT_VAR] = $priorEnv;
            }
            if ($priorServer === null) {
                unset($_SERVER[self::OPT_OUT_VAR]);
            } else {
                $_SERVER[self::OPT_OUT_VAR] = $priorServer;
            }
        }
    }
}
