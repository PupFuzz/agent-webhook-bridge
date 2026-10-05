<?php

namespace Tests\Unit\Support;

use App\Bridge\Exceptions\ConfigException;
use App\Bridge\Support\BoardToolsConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * card#11283 / DL-461 — which `board_tools` blocks are SCOPE-LESS (CI tools only), and that no
 * block that loaded before the change means anything new.
 */
class BoardToolsConfigScopelessTest extends TestCase
{
    /**
     * @param  array<string, mixed>  $block
     */
    private static function parse(array $block): ?BoardToolsConfig
    {
        return BoardToolsConfig::fromArray(['board_tools' => $block]);
    }

    public function test_an_explicit_block_with_no_scope_key_is_an_enabled_scope_less_agent(): void
    {
        $bt = self::parse(['enabled' => true, 'transport' => 'ssh', 'ssh_account' => 'kanban']);

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertTrue($bt->isScopeless());
        $this->assertNull($bt->boardId);
        $this->assertNull($bt->swimlaneId);
        $this->assertNull($bt->createStageId);
        $this->assertSame('ssh', $bt->transport);
        $this->assertSame('kanban', $bt->sshAccount);
        $this->assertTrue($bt->ciTools);
        $this->assertNull($bt->ciToolsProblem);
    }

    public function test_a_scoped_block_is_not_scope_less_and_parses_as_it_always_did(): void
    {
        $bt = self::parse(['enabled' => true, 'transport' => 'ssh', 'board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]);

        $this->assertNotNull($bt);
        $this->assertTrue($bt->enabled);
        $this->assertFalse($bt->isScopeless());
        $this->assertSame(10, $bt->boardId);
        $this->assertTrue($bt->ciTools);
    }

    /**
     * A partial scope — and a scope key PRESENT with a null value, which `array_key_exists`
     * counts as present — takes today's scoped path, and throws on the explicit block as it
     * always did.
     *
     * @return array<string, array{array<string, mixed>}>
     */
    public static function partialScopes(): array
    {
        return [
            'board_id only' => [['board_id' => 10]],
            'board_id and swimlane_id' => [['board_id' => 10, 'swimlane_id' => 4]],
            'a present-but-null board_id' => [['board_id' => null]],
            'a shared lane with no scope' => [['shared_swimlane_id' => 9]],
            'a coord board with no scope' => [['coord_board_id' => 3]],
            'address tags with no scope' => [['address_tags' => ['repo:x']]],
        ];
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    #[DataProvider('partialScopes')]
    public function test_a_partial_scope_on_an_explicit_block_still_throws(array $scope): void
    {
        $this->expectException(ConfigException::class);

        self::parse(['enabled' => true, 'transport' => 'ssh'] + $scope);
    }

    /**
     * @param  array<string, mixed>  $scope
     */
    #[DataProvider('partialScopes')]
    public function test_a_partial_scope_on_a_default_class_block_still_suppresses(array $scope): void
    {
        $bt = self::parse(['transport' => 'ssh'] + $scope);

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertNotNull($bt->suppressedReason);
    }

    /** Only the EXPLICIT form may omit the scope: a default-class block with none still suppresses. */
    public function test_a_default_class_block_with_no_scope_still_suppresses(): void
    {
        $bt = self::parse(['transport' => 'ssh']);

        $this->assertNotNull($bt);
        $this->assertFalse($bt->enabled);
        $this->assertFalse($bt->isScopeless());
        $this->assertNotNull($bt->suppressedReason);
    }

    /**
     * @return array<string, array{string, mixed}>
     */
    public static function scopedOnlyKeys(): array
    {
        return [
            'fleet_view' => ['fleet_view', true],
            'fleet_view false' => ['fleet_view', false],
            'description_max_bytes' => ['description_max_bytes', 4096],
        ];
    }

    #[DataProvider('scopedOnlyKeys')]
    public function test_a_key_that_needs_a_scope_throws_on_a_scope_less_block(string $key, mixed $value): void
    {
        $this->expectException(ConfigException::class);
        $this->expectExceptionMessage("board_tools.{$key} needs a board scope");

        self::parse(['enabled' => true, 'transport' => 'ssh', $key => $value]);
    }

    public function test_client_update_approval_is_honoured_on_a_scope_less_block(): void
    {
        $bt = self::parse(['enabled' => true, 'transport' => 'ssh', 'client_update' => ['approval_required' => true]]);

        $this->assertNotNull($bt);
        $this->assertTrue($bt->isScopeless());
        $this->assertTrue($bt->clientUpdateApprovalRequired);
    }

    /** The door half parses exactly as on a scoped block: an ssh block carrying `auth` still throws. */
    public function test_a_scope_less_block_keeps_the_transport_rules(): void
    {
        $this->expectException(ConfigException::class);

        self::parse(['enabled' => true, 'transport' => 'ssh', 'auth' => ['token_path' => '/x']]);
    }

    /**
     * @return array<string, array{array<string, mixed>, bool, bool}>
     */
    public static function ciToolsValues(): array
    {
        return [
            'absent' => [[], true, false],
            'true' => [['ci_tools' => true], true, false],
            'false' => [['ci_tools' => false], false, false],
            'bare (null)' => [['ci_tools' => null], false, true],
            'the string no' => [['ci_tools' => 'no'], false, true],
            'the string off' => [['ci_tools' => 'off'], false, true],
            'the int 0' => [['ci_tools' => 0], false, true],
        ];
    }

    /**
     * An opt-out is never a throw and never read as ON: an unreadable value is OFF and named.
     *
     * @param  array<string, mixed>  $ci
     */
    #[DataProvider('ciToolsValues')]
    public function test_ci_tools_is_an_opt_out_that_reads_anything_unreadable_as_off(array $ci, bool $on, bool $problem): void
    {
        foreach ([[], ['board_id' => 10, 'swimlane_id' => 4, 'create_stage_id' => 55]] as $scope) {
            $bt = self::parse(['enabled' => true, 'transport' => 'ssh'] + $scope + $ci);

            $this->assertNotNull($bt);
            $this->assertTrue($bt->enabled, 'an opt-out never takes the door down');
            $this->assertSame($on, $bt->ciTools);
            $this->assertSame($problem, $bt->ciToolsProblem !== null);
        }
    }
}
