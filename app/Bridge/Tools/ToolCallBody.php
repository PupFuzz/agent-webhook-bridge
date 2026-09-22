<?php

namespace App\Bridge\Tools;

use App\Console\Commands\Bridge\ToolsCallCommand;
use App\Http\Controllers\AgentTools\AgentToolsController;
use JsonException;
use stdClass;

/**
 * THE ONE PARSE OF A BOARD-TOOLS REQUEST BODY, for both front doors (card#10106).
 * {@see AgentToolsController} hands it the HTTP body, {@see ToolsCallCommand} hands it
 * STDIN; either gets back the decoded object or a refusal that names what is actually
 * wrong with the bytes, in the same words, before any field is read.
 *
 * ⛔ WHY THE HTTP DOOR CANNOT LEAVE THIS TO LARAVEL. `Request::json()` is
 * `(array) json_decode($content, true)` with no error check, so a truncated or
 * mis-encoded body becomes `[]`, the `tool` key reads as absent, and the caller is told
 * its request carries no `tool` — a true statement about the empty array and a false one
 * about a request whose `tool` is right there. A scalar body is cast to `[0 => value]` the
 * same way. Only a check on the RAW bytes can tell "no such key" from "never parsed".
 *
 * An empty object `{}` passes: it is a well-formed request with no `tool`, and the
 * dispatcher's own `tool` refusal is then the true answer.
 */
final class ToolCallBody
{
    /** The request shape every refusal here names, so a caller sees what WAS expected. */
    public const SHAPE = 'a JSON object {tool, args?, client_version?}';

    /**
     * @return array<string, mixed>|DispatchOutcome the decoded object, or a 422 refusal
     */
    public static function parse(string $raw): array|DispatchOutcome
    {
        if (trim($raw) === '') {
            return self::refuse('request body is empty');
        }

        try {
            $value = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            return self::refuse('request body is not valid JSON ('.$e->getMessage().')');
        }

        if (! $value instanceof stdClass) {
            return self::refuse('request body is a JSON '.self::jsonType($value).', not an object');
        }

        // Decoded a second time as an array because that is the shape every tool reads;
        // the first decode is what tells `{}` from `[]`, which an associative decode cannot.
        /** @var array<string, mixed> */
        return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
    }

    private static function refuse(string $what): DispatchOutcome
    {
        return DispatchOutcome::failure(422, $what.' — expected '.self::SHAPE);
    }

    private static function jsonType(mixed $value): string
    {
        return match (true) {
            is_array($value) => 'array',
            is_string($value) => 'string',
            is_bool($value) => 'boolean',
            $value === null => 'null',
            default => 'number',
        };
    }
}
