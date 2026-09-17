<?php

use Illuminate\Console\Application;
use Tests\Fixtures\Console\ChokeFixtureCommand;

// An `auto_prepend_file` for `php artisan`: registers ChokeFixtureCommand before the real
// `artisan` script boots, so that script runs unmodified. Composer's loader is idempotent,
// so `artisan` requiring it a second time is a no-op.

require __DIR__.'/../../../vendor/autoload.php';

Application::starting(static function (Application $artisan): void {
    $artisan->add(new ChokeFixtureCommand);
});
