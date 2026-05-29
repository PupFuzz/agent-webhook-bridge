<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when a per-agent config file is missing required fields, has an
 * invalid shape, or references an unresolvable classifier class.
 */
final class ConfigException extends RuntimeException {}
