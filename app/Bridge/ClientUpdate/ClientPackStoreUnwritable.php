<?php

namespace App\Bridge\ClientUpdate;

use RuntimeException;

/**
 * The published-pack store could not be written by THIS process (DL-430): it runs as the wrong
 * user, another publish holds the store's lock, or a directory or file write failed. Nothing about
 * the pack was judged, and what the store serves is unchanged — `published.json` is written last,
 * so a failure part-way leaves the previous publication in service.
 */
final class ClientPackStoreUnwritable extends RuntimeException {}
