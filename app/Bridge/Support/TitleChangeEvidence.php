<?php

namespace App\Bridge\Support;

/**
 * GitHub's evidence that a subject was RETITLED: the title as it stood before the edit,
 * read off the `changes` envelope an `edited` webhook carries beside the subject.
 *
 * ⭐ WHY THIS IS A PRIMITIVE AND NOT A HELPER ON EITHER CLASSIFIER. The string it returns
 * is not a convenience — it is the whole OWNERSHIP TEST two writeback arms gate a card-name
 * write on (DL-328 on a `pull_request.edited`, DL-341 on an `issues.edited`): the previous
 * title is exactly the name the bridge stamped when it minted the card, so a card whose
 * name still equals it byte for byte has been touched by nobody since. Two copies of that
 * narrowing is two places for the rule below to drift, and a drift here does not fail — it
 * writes a name onto a card the bridge does not own, which no later event corrects.
 *
 * THE RULE, stated once: GitHub sends `changes` with a key per field the edit changed, so
 * the `title` KEY's PRESENCE is the "the title changed" signal. A `changes.title` with no
 * usable `from` is NOT one, and is read as *no retitle* rather than as an empty previous
 * title — an empty previous title would compare equal to nothing, prove nothing, and (on a
 * card kanban stores with an empty name) restamp on no evidence at all.
 *
 * The ACTION GATE is deliberately NOT here and stays at each call site: the two consumers
 * gate on different event types (`pull_request.edited` / `issues.edited`) and each owns its
 * family's action constant, which is also what its `consumedEventTypes` declaration is
 * derived from. What is shared is the payload narrowing and the rule above, which is the
 * part that can silently disagree.
 */
final class TitleChangeEvidence
{
    /**
     * The subject's title as it stood BEFORE this edit, or null when the edit changed no
     * title (or carried no usable previous one). Pure: a boundary read of a foreign
     * payload, no I/O and no config.
     *
     * @param  array<mixed>  $payload  the raw webhook payload — `changes` sits at its top
     *                                 level, beside `pull_request` / `issue`
     */
    public static function previousTitle(array $payload): ?string
    {
        $changes = is_array($payload['changes'] ?? null) ? $payload['changes'] : [];
        $title = is_array($changes['title'] ?? null) ? $changes['title'] : [];
        $from = $title['from'] ?? null;

        return is_string($from) && $from !== '' ? $from : null;
    }
}
