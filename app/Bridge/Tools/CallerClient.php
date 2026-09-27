<?php

namespace App\Bridge\Tools;

use Illuminate\Support\Facades\Log;
use UnexpectedValueException;

/**
 * The calling channel client as far as the bridge can know it: the version it reported (card#8974)
 * and the capability table that says what each version declares (card#10566 / DL-425). Built once
 * per call by {@see BoardToolDispatcher::dispatch()} and handed to the tool, for
 * {@see RemedyText} to read.
 *
 * ⛔ TEXT ONLY (DL-426). Nothing may branch on this to refuse, accept or reshape a call — it
 * exists to tell a caller whose client is too old to have declared an argument so, without taking
 * away the escape. A caller at every version gets the same outcome it got before this existed.
 *
 * `$caps` is null when the bundled table could not be read. That is a broken deploy, and it is
 * logged, but it degrades to today's text and never to a failed call: a table read that threw out
 * of `dispatch()` would turn every board-tools call into a 500 over a sentence.
 */
final class CallerClient
{
    public function __construct(
        public readonly ?string $version,
        public readonly ?ClientCapabilities $caps,
    ) {}

    public static function reporting(?string $version): self
    {
        try {
            return new self($version, ClientCapabilities::bundled());
        } catch (UnexpectedValueException $e) {
            Log::warning('agent-tools: client capability table unreadable; remedy text carries no client-version clause', ['error' => $e->getMessage()]);

            return new self($version, null);
        }
    }
}
