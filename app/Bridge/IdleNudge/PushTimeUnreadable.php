<?php

namespace App\Bridge\IdleNudge;

use RuntimeException;

/**
 * When the lines an agent was pushed were last pushed could not be READ — which is neither
 * "recently" nor "long ago".
 */
final class PushTimeUnreadable extends RuntimeException {}
