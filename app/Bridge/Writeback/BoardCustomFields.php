<?php

namespace App\Bridge\Writeback;

/**
 * What ONE `boards/{id}/custom_fields.json` read says about a board's payload keys and which
 * VALUES each will take, so the create path and `bridge:check` ask one question of one read.
 *
 * `accepts()` answers for a STRING value by kanban's own per-type rule
 * (`CustomFieldValidator::validateValue()`): a `string` field takes one up to
 * {@see STRING_MAX_BYTES}; an `enum` takes one of its option values, each read the way
 * `optionValues()` reads it (its `value`, or the bare string); every other type — `number`,
 * `date`, `boolean`, `multi_select` (an array), `url` (an absolute http(s) URL), or a type kanban
 * does not know — refuses it. A record with no `type` (a kanban predating the field) is taken
 * to accept it, which is what the bridge assumed of every field before DL-392.
 */
final class BoardCustomFields
{
    /** kanban `CustomFieldValidator::validateString()`'s cap, measured with `strlen`. */
    public const STRING_MAX_BYTES = 4096;

    /**
     * @param  array<string, array{type: string|null, options: list<string>}>  $fieldsByKey  key => the
     *                                                                                       field's type and, for an enum, its option values
     */
    public function __construct(private readonly array $fieldsByKey) {}

    /** @param  array<mixed>  $fields  the read's `data` collection */
    public static function fromResponse(array $fields): self
    {
        $byKey = [];
        foreach ($fields as $f) {
            if (! is_array($f) || ! isset($f['key']) || ! is_string($f['key'])) {
                continue;
            }
            $type = is_string($f['type'] ?? null) ? $f['type'] : null;
            $values = [];
            if ($type === 'enum') {
                foreach (is_array($f['options'] ?? null) ? $f['options'] : [] as $opt) {
                    $value = is_array($opt) ? ($opt['value'] ?? null) : $opt;
                    if (is_string($value)) {
                        $values[] = $value;
                    }
                }
            }
            $byKey[$f['key']] = ['type' => $type, 'options' => $values];
        }

        return new self($byKey);
    }

    /**
     * Keys are cast back to string because kanban's key grammar (`[a-z0-9_]`) admits an
     * all-digit key, which PHP turns into an integer array key.
     *
     * @return list<string>
     */
    public function keys(): array
    {
        return array_map(strval(...), array_keys($this->fieldsByKey));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->fieldsByKey);
    }

    /** The field's declared type; null when the key is not registered or its record carries none. */
    public function type(string $key): ?string
    {
        return $this->fieldsByKey[$key]['type'] ?? null;
    }

    public function accepts(string $key, string $value): bool
    {
        if (! $this->has($key)) {
            return false;
        }

        return match ($this->fieldsByKey[$key]['type']) {
            null => true,
            'string' => strlen($value) <= self::STRING_MAX_BYTES,
            'enum' => in_array($value, $this->fieldsByKey[$key]['options'], true),
            default => false,
        };
    }
}
