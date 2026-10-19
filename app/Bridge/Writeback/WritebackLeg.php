<?php

namespace App\Bridge\Writeback;

use App\Bridge\Classifiers\CoordinationClassifier;
use App\Bridge\Classifiers\GitHubPrCardMoveClassifier;
use App\Bridge\Contracts\DrivesCoordCards;
use App\Bridge\Contracts\DrivesPrCardMoves;
use App\Bridge\Support\ClassifierResolver;

/**
 * The independently-driven legs of one `writeback.json` mapping, each bound to the mapping
 * keys whose effect ON THAT LEG needs a subscribed driver, and to the marker a classifier
 * implements to drive it (card#12050).
 *
 * ⛔ THE KEYS ARE NOT THE LEG'S ALONE. `stages` and `boards` also feed the board tools' start
 * and finished columns (`StartColumns`, `FinishedStages`) and `bridge:reconcile`, none of
 * which needs a classifier subscription — so a leg finding never tells the operator to
 * remove them, only to subscribe the leg's driver.
 *
 * A mapping is not one thing to cover: its PR leg is driven by a classifier that reads
 * `pull_request` events, its coord-card leg by one that reads `issues`, and the two shipped
 * emitters each drive exactly one. `bridge:check` asks each leg a mapping configures whether
 * some subscribed classifier implements that leg's marker — the binding lives here so the
 * keys, the marker and the shipped driver a finding names cannot drift apart per leg.
 */
enum WritebackLeg: string
{
    case PrCardMove = 'pr-card-move';
    case CoordCard = 'coord-card';

    /**
     * The legs `$classifierClass` declares it drives, probed OUT OF PROCESS (DL-025) so a
     * stale custom classifier cannot fatal the check. Empty for a classifier implementing
     * only the bare `App\Bridge\Contracts\EmitsWritebackReactions` marker.
     *
     * @return list<self>
     */
    public static function drivenBy(string $classifierClass): array
    {
        return array_values(array_filter(
            self::cases(),
            fn (self $leg): bool => ClassifierResolver::probeImplements($classifierClass, $leg->marker()),
        ));
    }

    /** @return class-string */
    public function marker(): string
    {
        return match ($this) {
            self::PrCardMove => DrivesPrCardMoves::class,
            self::CoordCard => DrivesCoordCards::class,
        };
    }

    /** @return class-string the shipped classifier that drives this leg, for a remedy to name */
    public function shippedDriver(): string
    {
        return match ($this) {
            self::PrCardMove => GitHubPrCardMoveClassifier::class,
            self::CoordCard => CoordinationClassifier::class,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::PrCardMove => 'PR → card-move leg',
            self::CoordCard => 'coord-card leg',
        };
    }

    /** What an operator loses while the leg has no driver, stated as what they will observe. */
    public function inertEffect(): string
    {
        return match ($this) {
            self::PrCardMove => 'merged PRs on this repo will not move their cards as they happen (`bridge:reconcile --fix` can still move a card that carries a `pr_url`)',
            self::CoordCard => 'no coordination issue on this repo is carded or moved in real time',
        };
    }

    /**
     * What ELSE reads this leg's keys without any classifier subscription, so a finding can
     * say why the keys stay. Null where nothing else does.
     */
    public function otherReaders(): ?string
    {
        return match ($this) {
            self::PrCardMove => 'Keep `stages` / `boards`: they also feed the board tools\' start and finished columns and `bridge:reconcile`, so a mapping that carries them only for those, with no PR mover on purpose, keeps this warning.',
            self::CoordCard => null,
        };
    }

    /**
     * The keys of `$mapping` whose effect on THIS leg needs a subscribed driver, as an
     * operator finds them in their own file. Empty when the mapping sets none of them. Not
     * exclusive to the leg — see the enum docblock and {@see self::otherReaders()}.
     *
     * `move_coord_cards` is read as the PARSED flag, which the DL-204 fleet default turns
     * on wherever `coord_card_terminal_stage_id` is present — so it is named with that
     * qualifier rather than as a key the operator may never have written.
     *
     * @return list<string>
     */
    public function configuredKeys(WritebackMapping $mapping): array
    {
        $keys = [];
        if ($this === self::PrCardMove) {
            if ($mapping->stages !== []) {
                $keys[] = 'stages';
            }
            if ($mapping->boards !== null) {
                $keys[] = 'boards';
            }
            if ($mapping->createDependabotCards) {
                $keys[] = 'create_dependabot_cards';
            }

            return $keys;
        }
        if ($mapping->createCoordCards) {
            $keys[] = 'create_coord_cards';
        }
        if ($mapping->moveCoordCards) {
            $keys[] = 'move_coord_cards (set, or on by the DL-204 default from coord_card_terminal_stage_id)';
        }

        return $keys;
    }
}
