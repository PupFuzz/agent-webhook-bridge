<?php

namespace App\Bridge\ClientUpdate;

use RuntimeException;

/**
 * A client pack, its manifest or the publication record was refused: its bytes, its release or
 * its checksums do not hold what the bridge requires before it will serve a pack to a seat
 * (DL-430). The message names what failed; nothing was published.
 */
final class ClientPackRefused extends RuntimeException {}
