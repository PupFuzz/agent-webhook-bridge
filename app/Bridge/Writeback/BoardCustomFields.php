<?php

namespace App\Bridge\Writeback;

/**
 * What ONE `boards/{id}/custom_fields.json` read says about a board's payload keys and which
 * VALUES each will take, so the create path and `bridge:check` ask one question of one read.
 *
 * ⚠ `accepts()` models ONE of kanban's per-type value rules — `enum` membership, read the way
 * kanban's `CustomFieldValidator::optionValues()` reads an option (its `value`, or the bare
 * string). Every other type answers true, as the bridge assumed for every field before DL-392:
 * a string constant sent into a `number` / `date` / `boolean` / `url` / `multi_select` field
 * still 422s at kanban, and nothing here predicts that.
 */
final class BoardCustomFields
{
    /**
     * @param  array<string, list<string>|null>  $enumOptionsByKey  key => the enum's allowed values;
     *                                                              null ⇒ the field is not an enum
     */
    public function __construct(private readonly array $enumOptionsByKey) {}

    /** @param  array<mixed>  $fields  the read's `data` collection */
    public static function fromResponse(array $fields): self
    {
        $byKey = [];
        foreach ($fields as $f) {
            if (! is_array($f) || ! isset($f['key']) || ! is_string($f['key'])) {
                continue;
            }
            if (($f['type'] ?? null) !== 'enum') {
                $byKey[$f['key']] = null;

                continue;
            }
            $values = [];
            foreach (is_array($f['options'] ?? null) ? $f['options'] : [] as $opt) {
                $value = is_array($opt) ? ($opt['value'] ?? null) : $opt;
                if (is_string($value)) {
                    $values[] = $value;
                }
            }
            $byKey[$f['key']] = $values;
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
        return array_map(strval(...), array_keys($this->enumOptionsByKey));
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->enumOptionsByKey);
    }

    public function accepts(string $key, string $value): bool
    {
        if (! $this->has($key)) {
            return false;
        }
        $options = $this->enumOptionsByKey[$key];

        return $options === null || in_array($value, $options, true);
    }
}
