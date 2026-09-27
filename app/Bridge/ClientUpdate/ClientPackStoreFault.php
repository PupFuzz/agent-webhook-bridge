<?php

namespace App\Bridge\ClientUpdate;

use RuntimeException;

/**
 * The published-pack store could not be used by THIS run (DL-430): this process may not write it
 * (root, or a store another user owns), another publish holds its lock, a directory or file write
 * failed, or its publication record could not be read or parsed. It says nothing about the pack
 * being published — `bridge:client-pack:install` reports it as could-not-measure (exit 2), never as
 * a refusal. What the store serves is unchanged: `published.json` is written last, so a write
 * failing part-way leaves the previous publication in service.
 */
final class ClientPackStoreFault extends RuntimeException
{
    /**
     * @param  string  $message  the fault; it can carry text read from a store file another account
     *                           could have written, so it is printed through the foreign-text escape
     * @param  ?string  $recovery  what the operator does next, composed by the store only from this
     *                             install's own paths and account names — printed as it is, never
     *                             through the escape (whose length bound would cut it)
     */
    public function __construct(string $message, public readonly ?string $recovery = null, ?\Throwable $previous = null)
    {
        parent::__construct($message, 0, $previous);
    }
}
