<?php

namespace App\Bridge\Tools;

use App\Bridge\Exceptions\ToolRefusalException;

/**
 * WHICH CARDS ARE THE CALLING SEAT'S (card#11267, rt#595): the cards ASSIGNED to its kanban user,
 * in any lane or in none, plus the UNASSIGNED cards in its home swimlane. The assignee wins
 * whenever it is set — a card in the seat's lane that another user holds is theirs, and a card the
 * seat holds in a topic lane is the seat's. The home lane only claims cards nobody holds yet.
 *
 * ⭐ ONE PLACE FOR THE RULE. `board_my_cards` selects with it, `board_take_card` widens its scope
 * by its assigned test ({@see isAssignedTo}, against the id the take resolves for itself), and
 * `board_create_card` writes the id this class resolves. That id comes from
 * {@see SeatKanbanUser::declaredForCallingSeat} — the roster, for the seat the door sealed — and
 * {@see forCallingSeat} is the one place this class asks it.
 *
 * ⚠ THE ASSIGNED ARM HAS THREE STATES, AND THE RESPONSE SAYS WHICH ONE ANSWERED:
 *  - {@see ARM_APPLIED}: the roster gives the seat a kanban user, so its assigned cards are
 *    selected wherever they are and a home-lane card another user holds is left out.
 *  - {@see ARM_NO_KANBAN_USER}: the roster gives the seat none. No card can be assigned to a seat
 *    with no kanban user, so the true reading is that its cards are the unassigned ones in its lane.
 *  - {@see ARM_UNAVAILABLE}: the roster could not say who the caller is (it is unreadable, or
 *    the id would not identify one seat). A READ does not refuse on that — the home lane is
 *    selected whole, as before this rule existed, because whether an assigned card in it is the
 *    caller's cannot be told — and `unavailable_reason` carries the resolver's own reason code.
 *    A take still refuses there: it resolves through {@see SeatKanbanUser::forCallingSeat}.
 */
final class SeatCardScope
{
    public const ARM_APPLIED = 'applied';

    public const ARM_NO_KANBAN_USER = 'no_kanban_user';

    public const ARM_UNAVAILABLE = 'unavailable';

    private function __construct(
        public readonly ?int $kanbanUserId,
        public readonly string $assigneeArm,
        public readonly ?string $unavailableReason,
    ) {}

    /**
     * The scope of the seat the door sealed for this call. Never refuses: a roster that cannot
     * identify the caller is {@see ARM_UNAVAILABLE}, with the resolver's reason (the resolver has
     * already logged it).
     */
    public static function forCallingSeat(string $tool): self
    {
        try {
            $kanbanUserId = SeatKanbanUser::declaredForCallingSeat($tool);
        } catch (ToolRefusalException $e) {
            return new self(null, self::ARM_UNAVAILABLE, $e->reason);
        }

        return new self($kanbanUserId, $kanbanUserId === null ? self::ARM_NO_KANBAN_USER : self::ARM_APPLIED, null);
    }

    /**
     * Whether the row names `$userId` as its assignee. A row whose `assigned_user_id` is absent,
     * null or not numeric names nobody.
     *
     * @param  array<string, mixed>  $row
     */
    public static function isAssignedTo(array $row, int $userId): bool
    {
        $holder = $row['assigned_user_id'] ?? null;

        return is_numeric($holder) && (int) $holder === $userId;
    }

    /**
     * Whether a row from the seat's OWN lane is the seat's: unassigned, or assigned to it.
     *
     * ⚠ A row whose assignee cannot be read is KEPT: it cannot be shown to be somebody else's, and
     * this is a read — the take that would write to it reads the holder again and refuses a row it
     * cannot read. Under {@see ARM_UNAVAILABLE} every lane row is kept, for the reason in the
     * class docblock.
     *
     * @param  array<string, mixed>  $row
     */
    public function claimsLaneRow(array $row): bool
    {
        if ($this->assigneeArm === self::ARM_UNAVAILABLE) {
            return true;
        }
        $holder = $row['assigned_user_id'] ?? null;
        if (! is_numeric($holder)) {
            return true;
        }

        return $this->kanbanUserId !== null && (int) $holder === $this->kanbanUserId;
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
     * @return array{kanban_user_id: ?int, assignee_arm: string, unavailable_reason: ?string, assigned_read_truncated: ?bool}
     */
    public function block(?bool $assignedReadTruncated): array
    {
        return [
            'kanban_user_id' => $this->kanbanUserId,
            'assignee_arm' => $this->assigneeArm,
            'unavailable_reason' => $this->unavailableReason,
            'assigned_read_truncated' => $assignedReadTruncated,
        ];
    }
}
