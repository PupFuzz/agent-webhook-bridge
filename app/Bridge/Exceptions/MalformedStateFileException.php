<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * A state file the bridge writes WHOLE is present and readable, and its bytes are not a record
 * the bridge wrote — the sibling of {@see UnreadableFileException}, which is the same file this
 * process could not open. Neither is ABSENCE, and a reader that folds either into "no record"
 * answers a question it never measured.
 *
 * Kept a separate type from the permissions fault on purpose: the remedy differs (the bytes are
 * wrong for every reader, so there is no other OS user who reads it fine), and a catch site that
 * widened one into the other would print the wrong one.
 */
class MalformedStateFileException extends RuntimeException
{
    public static function notARecord(string $path, string $problem): self
    {
        return new self("{$path} is not a record this bridge wrote ({$problem})");
    }
}
