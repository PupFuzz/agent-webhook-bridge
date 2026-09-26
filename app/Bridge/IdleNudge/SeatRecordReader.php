<?php

namespace App\Bridge\IdleNudge;

use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\PathVisibility;
use App\Bridge\Support\UntrustedPathContents;
use JsonException;

/**
 * Reads a seat's own offer record (schema v1, rt#562) — the file the coord `Stop` hook writes
 * at every turn end on a seat whose wake is enabled.
 *
 * ⛔ EVERY WAY TO NOT GET A V1 OFFER IS {@see SeatRecordUnmeasured}, each with its own verdict,
 * never an offer with nothing in it (consumer contract rule 5). Absent, not visible, unreadable,
 * not a JSON object, an unknown `v`, and a v1 record that breaks its own field contract are six
 * different facts about the install, and only the operator can tell which one to fix.
 *
 * ⚑ THE SEAT OWNS THE PATH, NOT THE BRIDGE. The record sits in the seat's home and is read by
 * the bridge's OS user, so it goes through {@see UntrustedPathContents} (a symlink is refused,
 * the read is bounded) after {@see PathVisibility} has established that "absent" is an answer
 * this process can give at all.
 */
final class SeatRecordReader
{
    public const VERSION = 1;

    /**
     * @throws SeatRecordUnmeasured
     */
    public function read(string $path): SeatOffer
    {
        if (! PathVisibility::ancestorIsTraversable($path)) {
            throw new SeatRecordUnmeasured('seat_record_not_visible');
        }
        try {
            $raw = UntrustedPathContents::read($path, 'idle nudge seat record');
        } catch (UnreadableFileException) {
            throw new SeatRecordUnmeasured('seat_record_unreadable');
        }
        if ($raw === null) {
            throw new SeatRecordUnmeasured('seat_record_absent');
        }

        try {
            $record = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new SeatRecordUnmeasured('seat_record_malformed');
        }
        if (! is_array($record) || array_is_list($record)) {
            throw new SeatRecordUnmeasured('seat_record_malformed');
        }
        // Checked before any other member: under an unknown version no field means what v1 says.
        if (($record['v'] ?? null) !== self::VERSION) {
            throw new SeatRecordUnmeasured('seat_record_unknown_version');
        }

        return $this->v1($record) ?? throw new SeatRecordUnmeasured('seat_record_malformed');
    }

    /**
     * The v1 field contract, member by member; null when any member breaks it.
     *
     * @param  array<mixed>  $r
     */
    private function v1(array $r): ?SeatOffer
    {
        $session = $r['session_id'] ?? null;
        $turn = $r['turn_ended_at'] ?? null;
        $horizon = $r['horizon_s'] ?? null;
        $cooldown = $r['cooldown_s'] ?? null;
        $prompt = $r['prompt'] ?? null;

        if (! is_string($r['agent'] ?? null) || $r['agent'] === ''
            || ($session !== null && ! is_string($session))
            || ! (is_int($turn) || is_float($turn)) || ! ($turn > 0) || ! ($turn * 1000 < PHP_INT_MAX)
            || ! is_int($horizon) || $horizon < 1
            || ! is_int($cooldown) || $cooldown < 1
            || ($prompt !== null && ! is_string($prompt))) {
            return null;
        }

        $lanes = $this->lanes($r['lanes'] ?? null);
        if ($lanes === false) {
            return null;
        }
        // The writer emits a prompt exactly when it offers lanes (rt#562 `prompt`); a record
        // that disagrees with itself on that is not one either half of it can be trusted from.
        $offers = $lanes !== null && $lanes !== [];
        if ($offers !== ($prompt !== null && trim($prompt) !== '')) {
            return null;
        }

        return new SeatOffer(
            sessionId: $session,
            turnEndedAtMs: (int) round($turn * 1000),
            horizonS: $horizon,
            cooldownS: $cooldown,
            lanes: $lanes,
            prompt: $offers ? $prompt : null,
        );
    }

    /**
     * @return list<array{lane: string, detail: string}>|null|false false = malformed
     */
    private function lanes(mixed $lanes): array|null|false
    {
        if ($lanes === null) {
            return null;
        }
        if (! is_array($lanes) || ! array_is_list($lanes)) {
            return false;
        }
        $out = [];
        foreach ($lanes as $entry) {
            if (! is_array($entry) || ! is_string($entry['lane'] ?? null) || $entry['lane'] === '' || ! is_string($entry['detail'] ?? null)) {
                return false;
            }
            $out[] = ['lane' => $entry['lane'], 'detail' => $entry['detail']];
        }

        return $out;
    }
}
