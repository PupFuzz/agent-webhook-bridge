<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Support\BridgePaths;
use App\Bridge\Support\Finding;
use Throwable;

/**
 * The inbox surfacing configuration — the layout and file-mode settings that decide where
 * staged intents are written and who can read them — migrated out of
 * `CheckCommand::handle()` (DL-242 stage 6).
 *
 * THE VERDICT TEXT IS `BridgePaths`', NOT THIS CLASS'S. `validateInboxConfig()` composes
 * each refusal (an invalid layout; a cross-user `BRIDGE_INBOX_GROUP` under a shared
 * layout, where a group-readable state dir would expose every agent's `inbox.jsonl`), and
 * restating any of it here would be a second copy to keep in step with the authority that
 * decides.
 *
 * THE LAYOUT CALL STAYS INSIDE THE GUARDED REGION. `inboxLayout()` throws on an invalid
 * layout in its own right, and the inline code composed the ok line inside the same `try`
 * that wrapped the validation — so a layout that validation somehow passed and rendering
 * did not still reports as a failure rather than escaping as an unhandled throw.
 *
 * NO GOLDEN FIXTURE REACHES THE FAILING PATH: all of them print the ok line. The failure
 * is also a `catch` arm, which the coverage instrument does not walk at all, so it is
 * ABSENT from the disclosed-gap list rather than listed as unobserved — absence there is
 * not protection. `InboxSurfacingConfigCheckTest` is the whole measurement.
 *
 * @see CheckSlot::Inbox
 */
final class InboxSurfacingConfigCheck implements Check
{
    public function id(): string
    {
        return 'install.inbox_config';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        try {
            BridgePaths::validateInboxConfig();
            $message = 'inbox surfacing config: ok (layout='.BridgePaths::inboxLayout().')';
        } catch (Throwable $e) {
            yield Finding::fail('inbox surfacing config: '.$e->getMessage());

            return;
        }

        yield Finding::ok($message);
    }
}
