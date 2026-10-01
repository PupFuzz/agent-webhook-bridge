<?php

namespace App\Bridge\Check\Checks;

use App\Bridge\Check\Check;
use App\Bridge\Check\CheckContext;
use App\Bridge\Check\CheckSlot;
use App\Bridge\Check\Silence;
use App\Bridge\Support\BoolEnv;
use App\Bridge\Support\Finding;
use App\Bridge\Support\UntrustedText;

/**
 * An on/off `.env` setting whose value the bridge could not read (card#11029).
 *
 * {@see BoolEnv} runs such a setting at its DEFAULT so the install keeps serving, and this is
 * where that stops being silent: one `fail` per key, naming the key and the value it could not
 * read. It reads `bridge.unreadable_flags`, the record `config/bridge.php` takes at config-load
 * time, so on a `config:cache`d install it reports the value that cache was built with — the
 * value actually running — and never re-reads a `.env` the cached install does not load.
 *
 * ⚑ ONLY THE KEYS IN `BoolEnv::KEYS` CAN APPEAR, and only their values are printed: they are
 * on/off flags, not secrets, and nothing else from the environment reaches this output.
 *
 * ⚑ `unvalidated` WHEN THE RECORD IS ABSENT. A config cache built by a release before this one
 * carries no `bridge.unreadable_flags`; reporting nothing there would read as "every flag was
 * readable" when nothing was measured.
 *
 * @see CheckSlot::Install
 */
final class InstallFlagValuesCheck implements Check
{
    public function id(): string
    {
        return 'install.flag_values';
    }

    /**
     * @return iterable<Finding|Silence>
     */
    public function run(CheckContext $ctx): iterable
    {
        $unreadable = config('bridge.unreadable_flags');
        if (! is_array($unreadable)) {
            yield Finding::unvalidated('on/off settings: bridge.unreadable_flags is not in the loaded config, so no on/off value was checked — a config cache built before this release; run `php artisan config:cache` again');

            return;
        }

        if ($unreadable === []) {
            yield Silence::because('every on/off setting that is set holds a readable value');

            return;
        }

        foreach ($unreadable as $key => $value) {
            yield Finding::fail(sprintf(
                "%s='%s' is not an on/off value, so the bridge is running with that setting's default — write true/false, 1/0, yes/no or on/off (docs/config-schema.md § 1), then run `php artisan config:cache` again if the config is cached",
                (string) $key,
                UntrustedText::forOperator((string) $value),
            ));
        }
    }
}
