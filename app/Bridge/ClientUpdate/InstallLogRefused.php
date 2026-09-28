<?php

namespace App\Bridge\ClientUpdate;

use RuntimeException;

/** An install-log line `client_report` will not store; the message says what is wrong with it. */
final class InstallLogRefused extends RuntimeException {}
