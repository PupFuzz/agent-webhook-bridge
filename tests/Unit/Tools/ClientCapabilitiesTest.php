<?php

namespace Tests\Unit\Tools;

use App\Bridge\Tools\ClientCapabilities;
use App\Bridge\Tools\ClientDeclaration;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

/**
 * The reader over the generated client capability table (card#10566 / DL-425), driven with a
 * hand-built table so every answer is checked against a span this test chose.
 *
 * The committed table itself is held against the channel server, the registry and history by
 * `ClientCapabilityTableTest` and CI's `gen-client-capabilities --check`; this class owns only
 * what the reader does with a table.
 */
class ClientCapabilitiesTest extends TestCase
{
    /**
     * `list_cards` 0.5.0 → gone at 0.9.0; `my_cards` since 0.5.0 with `limit` from 0.9.16 and
     * `legacy` from 0.6.0 to 0.9.2. Current client 0.9.27.
     *
     * @param  array<string, mixed>  $override
     */
    private static function table(array $override = []): string
    {
        return (string) json_encode(array_replace_recursive([
            'current_client_version' => '0.9.27',
            'features' => ['client_version_report' => '0.9.15'],
            'tools' => [
                'list_cards' => ['since' => '0.5.0', 'removed_in' => '0.9.0', 'arguments' => []],
                'my_cards' => ['since' => '0.5.0', 'removed_in' => null, 'arguments' => [
                    'limit' => ['since' => '0.9.16', 'removed_in' => null],
                    'legacy' => ['since' => '0.6.0', 'removed_in' => '0.9.2'],
                ]],
            ],
        ], $override));
    }

    private static function caps(): ClientCapabilities
    {
        return ClientCapabilities::fromJson(self::table());
    }

    public function test_since_and_removed_in_read_back_the_span(): void
    {
        $caps = self::caps();

        $this->assertSame('0.5.0', $caps->since('my_cards'));
        $this->assertSame('0.9.16', $caps->since('my_cards', 'limit'));
        $this->assertNull($caps->removedIn('my_cards', 'limit'));
        $this->assertSame('0.9.2', $caps->removedIn('my_cards', 'legacy'));
        $this->assertSame('0.9.15', $caps->feature('client_version_report'));
    }

    /** @return array<string, array{?string, string, ?string, ClientDeclaration}> */
    public static function declarations(): array
    {
        return [
            'at since' => ['0.9.16', 'my_cards', 'limit', ClientDeclaration::Yes],
            'one below since' => ['0.9.15', 'my_cards', 'limit', ClientDeclaration::No],
            // Numeric, not lexical: "0.9.9" sorts after "0.9.16" as a string.
            'numerically below since' => ['0.9.9', 'my_cards', 'limit', ClientDeclaration::No],
            'at current' => ['0.9.27', 'my_cards', 'limit', ClientDeclaration::Yes],
            'last before removal' => ['0.9.1', 'my_cards', 'legacy', ClientDeclaration::Yes],
            'at removal' => ['0.9.2', 'my_cards', 'legacy', ClientDeclaration::No],
            'removed tool' => ['0.9.16', 'list_cards', null, ClientDeclaration::No],
            'tool itself' => ['0.5.0', 'my_cards', null, ClientDeclaration::Yes],
            'no version reported' => [null, 'my_cards', 'limit', ClientDeclaration::Unknown],
            // R3-M5: compareVersions reads a chunk with no leading digit as 0, so `v1.0.0`
            // would order as 0.0.0. A reported version outside bare X.Y.Z is never compared.
            'v-prefixed' => ['v0.9.27', 'my_cards', 'limit', ClientDeclaration::Unknown],
            'v-prefixed newer' => ['v1.0.0', 'my_cards', null, ClientDeclaration::Unknown],
            'pre-release tag' => ['0.9.27-rc1', 'my_cards', 'limit', ClientDeclaration::Unknown],
            'newer than any client on record' => ['0.9.28', 'my_cards', 'limit', ClientDeclaration::Unknown],
        ];
    }

    #[DataProvider('declarations')]
    public function test_declares_answers_from_the_span(?string $version, string $tool, ?string $argument, ClientDeclaration $expected): void
    {
        $this->assertSame($expected, self::caps()->declares($version, $tool, $argument));
    }

    public function test_declared_at_is_the_set_a_version_carries(): void
    {
        $caps = self::caps();

        $this->assertSame(['list_cards' => [], 'my_cards' => ['legacy']], $caps->declaredAt('0.8.0'));
        $this->assertSame(['my_cards' => ['limit']], $caps->declaredAt('0.9.27'));
        $this->assertNull($caps->declaredAt('v0.9.27'));
    }

    public function test_gap_for_names_what_the_running_client_lacks(): void
    {
        $caps = self::caps();

        $this->assertSame(['my_cards' => ['limit']], $caps->gapFor('0.9.2', '0.9.27'));
        $this->assertSame([], $caps->gapFor('0.9.16', '0.9.27'));
        $this->assertSame(['my_cards' => ['limit']], $caps->gapFor('0.4.4', '0.9.27'), 'a missing tool lists every argument the target declares');
        $this->assertNull($caps->gapFor(null, '0.9.27'));
        $this->assertNull($caps->gapFor('v0.9.2', '0.9.27'));
    }

    public function test_an_untabled_tool_or_argument_is_refused_not_answered(): void
    {
        $caps = self::caps();

        try {
            $caps->declares('0.9.27', 'my_cards', 'nope');
            $this->fail('an untabled argument was answered');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('my_cards has no argument named nope', $e->getMessage());
        }
        $this->expectException(InvalidArgumentException::class);
        $caps->since('nope');
    }

    /**
     * R3-M5: every version the table STORES is bare X.Y.Z, in every position a version is
     * stored — the refusal is at load, so a table carrying one cannot be read at all.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function nonBareStored(): array
    {
        return [
            'current_client_version' => [['current_client_version' => 'v0.9.27']],
            'tool since' => [['tools' => ['my_cards' => ['since' => 'v0.5.0']]]],
            'tool removed_in' => [['tools' => ['list_cards' => ['removed_in' => 'v0.9.0']]]],
            'argument since' => [['tools' => ['my_cards' => ['arguments' => ['limit' => ['since' => 'v0.9.16']]]]]],
            'argument removed_in' => [['tools' => ['my_cards' => ['arguments' => ['legacy' => ['removed_in' => 'v0.9.2']]]]]],
            'feature' => [['features' => ['client_version_report' => 'v0.9.15']]],
            'pre-release' => [['tools' => ['my_cards' => ['since' => '0.5.0-rc1']]]],
            'leading zero' => [['features' => ['client_version_report' => '0.09.15']]],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('nonBareStored')]
    public function test_a_stored_version_that_is_not_bare_x_y_z_is_refused_at_load(array $override): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage('not a bare X.Y.Z version');

        ClientCapabilities::fromJson(self::table($override));
    }

    /** @return array<string, array{array<string, mixed>, string}> */
    public static function incoherent(): array
    {
        return [
            'since newer than current' => [['tools' => ['my_cards' => ['arguments' => ['limit' => ['since' => '0.9.30']]]]], 'newer than the current client'],
            'feature newer than current' => [['features' => ['not_yet_shipped' => '0.9.28']], 'newer than the current client'],
            'removed before added' => [['tools' => ['list_cards' => ['removed_in' => '0.5.0']]], 'no later than it is added'],
            'argument before its tool' => [['tools' => ['my_cards' => ['arguments' => ['limit' => ['since' => '0.4.0']]]]], 'before its tool'],
        ];
    }

    /** @param array<string, mixed> $override */
    #[DataProvider('incoherent')]
    public function test_an_incoherent_table_is_refused_at_load(array $override, string $why): void
    {
        $this->expectException(UnexpectedValueException::class);
        $this->expectExceptionMessage($why);

        ClientCapabilities::fromJson(self::table($override));
    }
}
