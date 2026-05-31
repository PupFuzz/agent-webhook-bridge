<?php

namespace App\Bridge\Exceptions;

use RuntimeException;

/**
 * Raised when a secret file exists but is group/world-readable (SSH-style
 * `mode & 0o077`). The message carries the PATH only, never the secret value.
 * Thrown by SecretFile::read (the API-token reader); the HMAC receiver and the
 * channel-token reader use the same SecretFile::isInsecure predicate but map it
 * to their own surface (a 500 status / a ChannelTokenException) — see DL-010.
 */
final class InsecureSecretPermsException extends RuntimeException {}
