<?php

namespace App\Bridge\Exceptions;

/**
 * The {@see UnreadableFileException} raised for a SECRET specifically — the third outcome of
 * `SecretFile::read`, thrown at the open in `TokenFile::readTrimmed` (both NAMED, never
 * `{@see}`-linked: pint rewrites a docblock FQCN into a real `use`, and importing `Support`
 * here would invert the layer). It used to be an `ErrorException` nobody documented and four
 * of seven callers never expected (card#5778). The message carries the PATH only, never the
 * secret value.
 *
 * THE UID-RELATIVE REASONING THAT MAKES THIS A TYPE AT ALL LIVES ON THE PARENT — read it
 * there rather than expecting a second copy here.
 *
 * IT STAYS A DISTINCT TYPE because six callers catch it to map a secret fault onto their own
 * surface — a 401, a `ConfigException`, an `unvalidated` finding — and those catches must not
 * silently widen the day one of those readers grows a second, non-secret read (card#5789
 * gave `BridgePaths`, `AgentRegistry`, `WritebackConfig` and `WebhookProvisioner` exactly
 * that shape). The parent carries the reasoning; this carries the subject.
 *
 * It is the same discrimination {@see ChannelTokenFault::NotReadable} draws for the channel
 * token (DL-260) — this is that ruling reaching the shared primitive.
 */
final class UnreadableSecretException extends UnreadableFileException {}
