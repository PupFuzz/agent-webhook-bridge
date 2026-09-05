<?php

namespace Tests\Feature\Console\Check;

use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * ⭐ THE OPERATOR-FACING SURFACE, END TO END (card#8433). `UrlValidatorTest` pins what the
 * validator THROWS; this pins what `bridge:check` actually PRINTS, because
 * `InstallEndpointUrlsCheck` renders the thrown message unchanged and that rendering is the
 * thing a person reads, pastes into a ticket and attaches to a bug report.
 *
 * ⛔ THE ABSENCE ASSERTION IS OVER THE WHOLE COMMAND'S OUTPUT, not over one finding. A
 * redaction that closed the validator while some other leg of the same run echoed the same
 * config value would leave the operator exactly where they started, and a per-finding
 * assertion would report that as clean.
 *
 * ⚠ SCOPED TO THE TWO INSTALL ENDPOINT URLS THIS CHECK OWNS. Other legs render other
 * config-supplied URLs (`ChannelTransportCheck`, `BoardToolsHttpProbeCheck`,
 * `WritebackAlertChannelCheck`) and card#8433 deliberately did not change what those print;
 * the planted value below is only ever set on the two keys named here, so this test says
 * nothing about those and does not pretend to.
 */
class EndpointUrlRedactionTest extends TestCase
{
    private const CANARY = 'CANARY8433SYNTHETICVALUE';

    /** @return list<array{0: string, 1: string, 2: string}> */
    public static function plantedEndpoints(): array
    {
        return [
            'api_base_url, credential in the query' => [
                'bridge.providers.kanban.api_base_url',
                'http://kanban.internal/api/v3?api_token='.self::CANARY,
                'http://kanban.internal/api/v3?[REDACTED]',
            ],
            'api_base_url, credential in the userinfo' => [
                'bridge.providers.kanban.api_base_url',
                'http://svc:'.self::CANARY.'@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
            'receiver_base_url, credential in the userinfo' => [
                'bridge.receiver_base_url',
                'ftp://svc:'.self::CANARY.'@bridge.example.com/webhooks',
                'ftp://***@bridge.example.com/webhooks',
            ],
            // ⭐ THE TWO SHAPES THAT ESCAPED THE FIRST CUT OF THE RULE, on the operator
            // surface they escaped it on. A userinfo bounded by `[^/?#]*` cannot reach the
            // `@` behind a `/`, `?` or `#`, and all three occur in generated passwords (`/`
            // is base64). Both were measured through this command's real output before the
            // bound was changed. The `#` shape behaves exactly as `?` does and is pinned in
            // `SecretScrubberTest` rather than paying for a fourth full `bridge:check` run.
            'api_base_url, a SLASH inside the password — the whole value came back' => [
                'bridge.providers.kanban.api_base_url',
                'http://svc:'.self::CANARY.'/x@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
            // ⛔ THE DANGEROUS ONE: the old output CARRIED `[REDACTED]`
            // (`http://svc:CANARY…?[REDACTED]`), so the operator, the reviewer and any
            // presence-of-`[REDACTED]` assertion all read a leak as a redaction. Only the
            // pairing of the canary-absence with the EXACT survivor catches that.
            'api_base_url, a QUESTION MARK inside the password — the head survived' => [
                'bridge.providers.kanban.api_base_url',
                'http://svc:'.self::CANARY.'?x@kanban.internal/api/v3',
                'http://***@kanban.internal/api/v3',
            ],
        ];
    }

    #[DataProvider('plantedEndpoints')]
    public function test_a_planted_credential_never_reaches_the_operator_report(string $key, string $value, string $expected): void
    {
        config([$key => $value]);

        Artisan::call('bridge:check');
        $output = Artisan::output();

        $this->assertStringNotContainsString(self::CANARY, $output);
        // The PRESENCE half. Absence alone is satisfied by a check that stopped yielding
        // the finding at all, which would be a worse outcome than the leak it replaced.
        $this->assertStringContainsString($expected, $output);
        $this->assertStringContainsString($key, $output);
    }

    public function test_the_json_report_is_covered_by_the_same_redaction(): void
    {
        // The `--format=json` document is a second rendering of the same findings and is
        // what a hook consumes and logs. A redaction pinned only on the text report would
        // leave the machine-read surface open.
        config(['bridge.providers.kanban.api_base_url' => 'http://svc:'.self::CANARY.'@kanban.internal/api/v3']);

        Artisan::call('bridge:check', ['--format' => 'json']);
        $output = Artisan::output();

        $this->assertStringNotContainsString(self::CANARY, $output);
        $this->assertStringContainsString('http://***@kanban.internal/api/v3', $output);
    }
}
