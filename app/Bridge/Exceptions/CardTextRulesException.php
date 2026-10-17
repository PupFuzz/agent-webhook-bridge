<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * The card-text rule file (the toolkit's `_card-text-rules.json`, vendored at
 * `resources/card-text-rules.json`) could not be loaded or holds something this reader cannot
 * apply: unreadable, not JSON, a schema or schema_version it does not know, an unknown check kind,
 * a pattern that does not compile or matches the empty string, or an alternation whose
 * POSIX-longest and PCRE-first answers could differ. Raised instead of passing the text unchecked,
 * as kbcard refuses rather than passes when its rules cannot be read.
 */
final class CardTextRulesException extends RuntimeException {}
