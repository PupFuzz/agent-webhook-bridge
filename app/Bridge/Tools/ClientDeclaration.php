<?php

namespace App\Bridge\Tools;

/**
 * Whether a channel-server client at a given version declares a board tool or argument
 * ({@see ClientCapabilities::declares()}).
 *
 * `Unknown` is its own answer and never a softer `No`: it is what the table says when the
 * client reported no version, a version the comparator cannot order, or a version newer than
 * any this checkout has a record of.
 */
enum ClientDeclaration
{
    case Yes;
    case No;
    case Unknown;
}
