<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Exceptions\UnreadableFileException;
use App\Bridge\Support\Finding;
use App\Bridge\Support\ReceiverPhpLimits;
use App\Bridge\Support\RedactedErrorText;

/**
 * Is the WEB SAPI's `post_max_size` at least `bridge.max_body_bytes`? (card#12576)
 *
 * `bridge:check` is the step every update runs (`CLAUDE_DEPLOYMENT.md` § Update an existing
 * install), so this is where an install learns that a raised body cap and its PHP disagree. It
 * WARNS and never fails: below the cap a JSON delivery still reaches the bridge whole on PHP-FPM
 * 8.5 — measured — and PHP logs a startup warning for it; the remedy is the operator's ini edit.
 *
 * THE VALUE IS THE ONE THE RECEIVER RECORDED ({@see ReceiverPhpLimits}), never this CLI process's
 * `ini_get()`: the CLI loads a different php.ini, and a pool or vhost override applies only inside
 * the web request. No record yet — the first update that ships this, before a webhook arrives — is
 * `unvalidated`, worded UNMEASURED, with the commands that read the value by hand. A value of `0`
 * or below is PHP's "no limit" (measured for `0` and `-1` under FPM 8.5).
 */
final class ReceiverPostMaxSizeCheck implements Check
{
    private const MIB = 1048576;

    public function id(): string
    {
        return 'install.receiver_post_max_size';
    }

    /**
     * @return iterable<Finding>
     */
    public function run(CheckContext $ctx): iterable
    {
        $cap = (int) config('bridge.max_body_bytes');
        $minimum = (int) ceil($cap / self::MIB).'M';

        try {
            $record = ReceiverPhpLimits::read();
        } catch (UnreadableFileException $e) {
            yield Finding::unvalidated('receiver post_max_size: UNMEASURED — '.RedactedErrorText::of($e));

            return;
        }
        $raw = $record['ini']['post_max_size'] ?? null;
        if ($record === null || $raw === null) {
            yield Finding::unvalidated("receiver post_max_size: UNMEASURED — the web PHP that serves the receiver records its limits on the first webhook it serves after this update, and has not yet (this command's own PHP is the CLI, a different ini, and does not answer it). It must be at least {$minimum} (bridge.max_body_bytes is {$cap} B). Read it now: `php-fpm".PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION." -i | grep -E '^(Loaded Configuration File|post_max_size)'` (use the php-fpmX.Y your pool runs), then `sudo grep -rn post_max_size /etc/php/*/fpm/pool.d/` — a pool's php_admin_value/php_value overrides the ini (Apache mod_php: the apache2 php.ini and the vhost's php_value). Or re-run this after the next delivery.");

            return;
        }

        $where = "{$record['sapi']}, PHP {$record['php_version']}, recorded {$record['recorded_at']}";
        if (preg_match('/\A\s*[+-]?\d+\s*[kKmMgG]?\s*\z/', $raw) !== 1) {
            yield Finding::unvalidated("receiver post_max_size: UNMEASURED — the web PHP ({$where}) reported post_max_size '{$raw}', which this check cannot read as a size. It must be at least {$minimum} (bridge.max_body_bytes is {$cap} B).");

            return;
        }
        $bytes = ini_parse_quantity($raw);
        if ($bytes <= 0) {
            yield Finding::ok("receiver post_max_size: {$raw} (no limit) in the web PHP ({$where})");

            return;
        }
        if ($bytes >= $cap) {
            yield Finding::ok("receiver post_max_size: {$raw} ({$bytes} B) ≥ bridge.max_body_bytes {$cap} B in the web PHP ({$where})");

            return;
        }

        yield Finding::warn("receiver post_max_size: {$raw} ({$bytes} B) in the web PHP ({$where}) is below bridge.max_body_bytes {$cap} B. A delivery between the two still reaches the bridge whole on PHP-FPM 8.5 (measured), but PHP logs `POST Content-Length of N bytes exceeds the limit` for each one, and other PHP versions and SAPIs were not measured. Raise post_max_size to at least {$minimum} {$this->remedy($record)}");
    }

    /**
     * Where to make the edit, and what to reload, for the SAPI that recorded the value.
     *
     * @param  array{sapi: string, php_version: string, ini_file: ?string}  $record
     */
    private function remedy(array $record): string
    {
        $ini = $record['ini_file'] ?? 'the php.ini that SAPI loads';
        $v = $record['php_version'];

        return match ($record['sapi']) {
            'fpm-fcgi' => "in {$ini} — or in this install's FPM pool file if it sets php_admin_value[post_max_size] or php_value[post_max_size] (`sudo grep -rn post_max_size /etc/php/{$v}/fpm/pool.d/`), which overrides the ini — then `sudo systemctl reload php{$v}-fpm`.",
            'apache2handler' => "in {$ini} — or in the vhost / .htaccess if it sets php_value post_max_size, which overrides the ini — then `sudo systemctl reload apache2`.",
            default => "in {$ini} (SAPI {$record['sapi']}), or wherever that SAPI overrides it, then reload that server.",
        };
    }
}
