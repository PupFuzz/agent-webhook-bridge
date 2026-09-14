<?php

namespace App\Bridge\IdleNudge;

use RuntimeException;

/**
 * An agent's inbox view could not be READ — which is not the same answer as "it holds nothing".
 */
final class InboxUnreadable extends RuntimeException {}
