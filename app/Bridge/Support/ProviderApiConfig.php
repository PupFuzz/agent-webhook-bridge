<?php

namespace App\Bridge\Support;

use App\Bridge\Exceptions\ConfigException;

/**
 * Per-provider API endpoint + token-file path (used by bridge:provision to
 * register webhook subscriptions against the upstream). The token VALUE is
 * never stored in config — only the path to read it from at use time.
 */
final class ProviderApiConfig
{
    public function __construct(
        public readonly string $baseUrl,
        public readonly string $tokenPath,
    ) {}

    /**
     * @param  array<mixed>  $data
     */
    public static function fromArray(string $name, array $data): self
    {
        foreach (['base_url', 'token_path'] as $key) {
            if (! array_key_exists($key, $data)) {
                throw new ConfigException("api.{$name}: missing required key: {$key}");
            }
        }

        return new self(
            baseUrl: rtrim((string) (is_scalar($data['base_url']) ? $data['base_url'] : ''), '/'),
            tokenPath: PathHelper::expandUser((string) (is_scalar($data['token_path']) ? $data['token_path'] : '')),
        );
    }
}
