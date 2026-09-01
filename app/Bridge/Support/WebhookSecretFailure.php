<?php

namespace App\Bridge\Support;

/**
 * Why the per-(provider, scope) HMAC secret could not be resolved by
 * {@see WebhookSecretResolver}.
 *
 * ⛔ THESE CASE VALUES ARE THE RECEIVER'S WIRE REASONS. `VerifyHmacSignature` returns
 * them as the response body, and kanban-board's retry behaviour is keyed on the STATUS
 * the middleware pairs with each one — so renaming a case value changes what the
 * upstream is told, and is not a refactor. The one exception is `SecretUnreadable`,
 * which the receiver deliberately reports AS `unknown_scope` (see the middleware): an
 * unauthenticated caller learns nothing about the install's filesystem either way, and
 * kanban-board's retry decision must not change with the OS permission that produced it.
 *
 * The status mapping does NOT live here: it is the receiver's HTTP contract, owned by
 * the middleware that documents it, while this vocabulary is also consumed by a CLI
 * (`bridge:sign`) that has no statuses at all and needs the finer distinction.
 */
enum WebhookSecretFailure: string
{
    case ConfigSecretDirMissing = 'config_secret_dir_missing';

    case ConfigSecretDirNotAbsolute = 'config_secret_dir_not_absolute';

    case SecretPermsInsecure = 'secret_perms_insecure';

    /** There is no secret file at this (provider, scope)'s path. */
    case UnknownScope = 'unknown_scope';

    /**
     * A secret file IS there and THIS process could not read it — not the same claim as
     * absence, and not a claim about any other reader (card#5789). The receiver cannot
     * act on the difference; an operator running `bridge:sign` as the wrong OS user can,
     * and that is the whole reason the two are separate cases.
     */
    case SecretUnreadable = 'secret_unreadable';

    case EmptySecretFile = 'empty_secret_file';
}
