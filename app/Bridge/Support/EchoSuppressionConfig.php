<?php

namespace App\Bridge\Support;

/**
 * The echo_suppression section of a per-agent config. treat_as_echo /
 * treat_as_signal match friendly names (via the registry); treat_as_echo_ids
 * match raw provider ids directly (the load-bearing loop-safety net that
 * works even with no registry).
 */
final class EchoSuppressionConfig
{
    /**
     * @param  list<string>  $treatAsEcho
     * @param  list<string>  $treatAsSignal
     * @param  list<string>  $treatAsEchoIds
     */
    public function __construct(
        public readonly array $treatAsEcho = [],
        public readonly array $treatAsSignal = [],
        public readonly array $treatAsEchoIds = [],
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            treatAsEcho: StringList::coerce($data['treat_as_echo'] ?? []),
            treatAsSignal: StringList::coerce($data['treat_as_signal'] ?? []),
            treatAsEchoIds: StringList::coerce($data['treat_as_echo_ids'] ?? []),
        );
    }
}
