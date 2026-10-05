<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;
use App\Bridge\Support\RosterKanbanUser;

/**
 * WHICH CARDS ARE THE CALLING SEAT'S (card#11267, rt#595): the cards ASSIGNED to its kanban user,
 * in any lane or in none, plus the UNASSIGNED cards in its home swimlane. The assignee wins
 * whenever it is set — a card in the seat's lane that another user holds is theirs, and a card the
 * seat holds in a topic lane is the seat's. The home lane only claims cards nobody holds yet.
 *
 * ⭐ ONE PLACE FOR THE RULE. `board_my_cards` selects with it, `board_take_card` widens its scope
 * by its assigned test ({@see isAssignedTo}, against the id the take resolves for itself), and
 * `board_create_card` writes the id this class resolves; `board_correct_card`'s assignee arm
 * compares with {@see isAssignedTo} too. That id comes from
 * {@see SeatKanbanUser::declaredVerdictForCallingSeat} — the roster, for the seat the door sealed —
 * and {@see forCallingSeat} is the one place this class asks it.
 *
 * ⚠ THE ASSIGNED ARM HAS THREE STATES, AND THE RESPONSE SAYS WHICH ONE ANSWERED:
 *  - {@see ARM_APPLIED}: the roster gives the seat a kanban user, so its assigned cards are
 *    selected wherever they are and a home-lane card another user holds is left out.
 *  - {@see ARM_NO_KANBAN_USER}: the roster gives the seat no kanban user ON THIS HOST — the seat is
 *    absent from the roster, or present with no usable id for this kanban host, and
 *    `no_kanban_user_reason` says which. ⚠ That is NOT "no card can be assigned to the seat": the
 *    seat may hold cards under an id the roster has not recorded for this host, so whether a held
 *    card in its lane is its own cannot be told, and the home lane is selected WHOLE, exactly as
 *    under {@see ARM_UNAVAILABLE}. What it does mean is that there is no id to match, so the board
 *    is not walked for assigned cards outside the lane.
 *  - {@see ARM_UNAVAILABLE}: the roster could not say who the caller is (it is unreadable, or
 *    the id would not identify one seat). A READ does not refuse on that — the home lane is
 *    selected whole, as before this rule existed, because whether an assigned card in it is the
 *    caller's cannot be told — and `unavailable_reason` carries the resolver's own reason code.
 *    A take still refuses there: it resolves through {@see SeatKanbanUser::forCallingSeat}.
 *
 * ⛔ "ASSIGNED TO" IS STRICT: only an integer `assigned_user_id` identical to the id names the seat
 * ({@see isAssignedTo}). It is a PERMISSION test on the take's assigned arm and on
 * `board_correct_card`'s assignee arm, so a numeric string, a float or a decorated value never
 * matches anybody.
 */
final class SeatCardScope
{
    public const ARM_APPLIED = 'applied';

    public const ARM_NO_KANBAN_USER = 'no_kanban_user';

    public const ARM_UNAVAILABLE = 'unavailable';

    /** {@see ARM_NO_KANBAN_USER}'s roster case: the seat is not in the roster at all. */
    public const ROSTER_SEAT_ABSENT = 'roster_seat_absent';

    /** {@see ARM_NO_KANBAN_USER}'s roster case: the seat is in the roster with no usable id for this kanban host. */
    public const NO_KANBAN_ID_FOR_HOST = 'no_kanban_id_for_host';

    private function __construct(
        public readonly ?int $kanbanUserId,
        public readonly string $assigneeArm,
        public readonly ?string $unavailableReason,
        public readonly ?string $noKanbanUserReason = null,
    ) {}

    /**
     * The scope of the seat the door sealed for this call. Never refuses: a roster that cannot
     * identify the caller is {@see ARM_UNAVAILABLE}, with the resolver's reason (the resolver has
     * already logged it).
     */
    public static function forCallingSeat(string $tool): self
    {
        try {
            $verdict = SeatKanbanUser::declaredVerdictForCallingSeat($tool);
        } catch (ToolRefusalException $e) {
            return new self(null, self::ARM_UNAVAILABLE, $e->reason);
        }

        if ($verdict->userId !== null) {
            return new self($verdict->userId, self::ARM_APPLIED, null);
        }

        return new self(null, self::ARM_NO_KANBAN_USER, null, $verdict->why === RosterKanbanUser::ABSENT ? self::ROSTER_SEAT_ABSENT : self::NO_KANBAN_ID_FOR_HOST);
    }

    /**
     * Whether the row names `$userId` as its assignee: its `assigned_user_id` is an INTEGER identical
     * to it. Absent, null, a numeric string (`"42"`), a float or anything else names nobody — no
     * coercion, because this answers a permission question (class docblock).
     *
     * @param  array<string, mixed>  $row
     */
    public static function isAssignedTo(array $row, int $userId): bool
    {
        return ($row['assigned_user_id'] ?? null) === $userId;
    }

    /**
     * Whether a row from the seat's OWN lane is the seat's: unassigned, or assigned to it.
     *
     * ⚠ A row whose assignee is not an integer — null (unassigned), absent, or a value the strict
     * test cannot read — is KEPT: it cannot be shown to be somebody else's, and this is a read —
     * the take that would write to it reads the holder again and refuses a row it cannot read.
     * Under {@see ARM_UNAVAILABLE} and {@see ARM_NO_KANBAN_USER} every lane row is kept, for the
     * reasons in the class docblock: with no id for the seat, no holder can be shown not to be it.
     *
     * @param  array<string, mixed>  $row
     */
    public function claimsLaneRow(array $row): bool
    {
        if ($this->kanbanUserId === null) {
            return true;
        }
        $holder = $row['assigned_user_id'] ?? null;

        return ! is_int($holder) || $holder === $this->kanbanUserId;
    }

    /**
     * Whether a row from OUTSIDE the seat's lane is the seat's: only when it is assigned to it.
     *
     * @param  array<string, mixed>  $row
     */
    public function claimsAssignedRow(array $row): bool
    {
        return $this->kanbanUserId !== null && self::isAssignedTo($row, $this->kanbanUserId);
    }

    /**
     * The `selection` block `board_my_cards` reports, so a caller can tell which arm answered.
     *
     * @return array{kanban_user_id: ?int, assignee_arm: string, unavailable_reason: ?string, no_kanban_user_reason: ?string, assigned_read_truncated: ?bool}
     */
    public function block(?bool $assignedReadTruncated): array
    {
        return [
            'kanban_user_id' => $this->kanbanUserId,
            'assignee_arm' => $this->assigneeArm,
            'unavailable_reason' => $this->unavailableReason,
            'no_kanban_user_reason' => $this->noKanbanUserReason,
            'assigned_read_truncated' => $assignedReadTruncated,
        ];
    }
}
