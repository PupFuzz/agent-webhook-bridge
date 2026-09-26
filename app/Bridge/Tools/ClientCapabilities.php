<?php

namespace App\Bridge\Tools;

use App\Bridge\Support\ChannelSnapshotManifest;
use InvalidArgumentException;
use UnexpectedValueException;

/**
 * Which reference channel-server version first declared each board tool and argument, and
 * which stopped — read from `resources/client-capabilities.json` (card#10566 / DL-425).
 *
 * ⛔ THE TABLE IS GENERATED, AND THIS CLASS DOES NOT DERIVE ANYTHING. `tools` and
 * `current_client_version` are written by `bin/gen-client-capabilities.mjs` from the git
 * history of `examples/channel-servers/`, and CI's `--check` step reds when the committed file is
 * not what history derives. `features` is hand-declared and held by `ClientCapabilityTableTest`.
 * DL-425 owns which history states are read and why.
 *
 * ⛔ EVERY STORED VERSION IS BARE `X.Y.Z`, REFUSED AT LOAD OTHERWISE. The comparator is
 * {@see ChannelSnapshotManifest::compareVersions()} — deliberately the one already held in
 * lockstep with the provisioner — and it reads a chunk with no leading digit as 0, so a stored
 * `v1.0.0` would order as 0.0.0 and every client would be told it declares nothing. A REPORTED
 * version gets the same test and answers {@see ClientDeclaration::Unknown} when it fails, rather
 * than being compared.
 */
final class ClientCapabilities
{
    public const TABLE = 'resources/client-capabilities.json';

    private const BARE_VERSION = '/^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\z/';

    /**
     * @param  array<string, array{since: string, removed_in: ?string, arguments: array<string, array{since: string, removed_in: ?string}>}>  $tools
     * @param  array<string, string>  $features
     */
    public function __construct(
        public readonly string $currentClientVersion,
        private readonly array $tools,
        private readonly array $features,
    ) {
        self::assertBare($currentClientVersion, 'current_client_version');
        foreach ($features as $name => $version) {
            self::assertBare($version, "features.{$name}");
            $this->assertNotAhead($version, "features.{$name}");
        }
        foreach ($tools as $tool => $span) {
            $this->assertSpan($span, $tool);
            foreach ($span['arguments'] as $argument => $argSpan) {
                $this->assertSpan($argSpan, "{$tool}.{$argument}");
                if (ChannelSnapshotManifest::compareVersions($argSpan['since'], $span['since']) < 0) {
                    throw new UnexpectedValueException("client capability table: {$tool}.{$argument} is dated {$argSpan['since']}, before its tool ({$span['since']})");
                }
            }
        }
    }

    public static function bundled(): self
    {
        $raw = @file_get_contents(base_path(self::TABLE));
        if ($raw === false) {
            throw new UnexpectedValueException('client capability table: '.self::TABLE.' did not read');
        }

        return self::fromJson($raw);
    }

    public static function fromJson(string $json): self
    {
        $decoded = json_decode($json, true);
        if (! is_array($decoded) || ! is_string($decoded['current_client_version'] ?? null) || ! is_array($decoded['tools'] ?? null) || ! is_array($decoded['features'] ?? null)) {
            throw new UnexpectedValueException('client capability table: not a JSON object carrying current_client_version, tools and features');
        }

        $tools = [];
        foreach ($decoded['tools'] as $tool => $span) {
            $tools[(string) $tool] = self::span($span, (string) $tool, true);
        }
        $features = [];
        foreach ($decoded['features'] as $name => $version) {
            if (! is_string($version)) {
                throw new UnexpectedValueException("client capability table: features.{$name} is not a string");
            }
            $features[(string) $name] = $version;
        }

        /** @var array<string, array{since: string, removed_in: ?string, arguments: array<string, array{since: string, removed_in: ?string}>}> $tools */
        return new self($decoded['current_client_version'], $tools, $features);
    }

    /**
     * The first client version that declared `$tool` (or its `$argument`).
     *
     * @throws InvalidArgumentException when the table does not know it — a board tool or
     *                                  argument is always tabled (`ClientCapabilityTableTest`),
     *                                  so this is a caller asking about something no client has
     */
    public function since(string $tool, ?string $argument = null): string
    {
        return $this->spanOf($tool, $argument)['since'];
    }

    public function removedIn(string $tool, ?string $argument = null): ?string
    {
        return $this->spanOf($tool, $argument)['removed_in'];
    }

    public function feature(string $name): string
    {
        return $this->features[$name] ?? throw new InvalidArgumentException("client capability table: no feature named {$name}");
    }

    /**
     * @return array<string, string> feature name => the client version that first carries it
     */
    public function features(): array
    {
        return $this->features;
    }

    public function declares(?string $version, string $tool, ?string $argument = null): ClientDeclaration
    {
        $span = $this->spanOf($tool, $argument);
        if (! $this->comparable($version)) {
            return ClientDeclaration::Unknown;
        }

        return $this->within($version, $span) ? ClientDeclaration::Yes : ClientDeclaration::No;
    }

    /**
     * The tools and arguments a client at `$version` declares.
     *
     * @return array<string, list<string>>|null tool => its declared arguments, sorted; null when
     *                                          `$version` is not one the table can answer for
     */
    public function declaredAt(?string $version): ?array
    {
        if (! $this->comparable($version)) {
            return null;
        }

        $declared = [];
        foreach ($this->tools as $tool => $span) {
            if (! $this->within($version, $span)) {
                continue;
            }
            $arguments = array_keys(array_filter($span['arguments'], fn (array $a): bool => $this->within($version, $a)));
            sort($arguments);
            $declared[$tool] = $arguments;
        }
        ksort($declared);

        return $declared;
    }

    /**
     * What a client at `$running` lacks that a client at `$target` declares.
     *
     * @return array<string, list<string>>|null tool => the arguments `$target` declares and
     *                                          `$running` does not (every argument, when the tool
     *                                          itself is missing); null when either version is
     *                                          not one the table can answer for
     */
    public function gapFor(?string $running, string $target): ?array
    {
        $have = $this->declaredAt($running);
        $want = $this->declaredAt($target);
        if ($have === null || $want === null) {
            return null;
        }

        $gap = [];
        foreach ($want as $tool => $arguments) {
            $missing = array_values(array_diff($arguments, $have[$tool] ?? []));
            if (! isset($have[$tool]) || $missing !== []) {
                $gap[$tool] = $missing;
            }
        }

        return $gap;
    }

    /**
     * A version this table can answer for: bare `X.Y.Z` and not newer than the newest client
     * this checkout has a record of — a later client may have dropped anything.
     *
     * @phpstan-assert-if-true string $version
     */
    private function comparable(?string $version): bool
    {
        return $version !== null
            && preg_match(self::BARE_VERSION, $version) === 1
            && ChannelSnapshotManifest::compareVersions($version, $this->currentClientVersion) <= 0;
    }

    /**
     * @param  array{since: string, removed_in: ?string}  $span
     */
    private function within(string $version, array $span): bool
    {
        return ChannelSnapshotManifest::compareVersions($version, $span['since']) >= 0
            && ($span['removed_in'] === null || ChannelSnapshotManifest::compareVersions($version, $span['removed_in']) < 0);
    }

    /**
     * @return array{since: string, removed_in: ?string}
     */
    private function spanOf(string $tool, ?string $argument): array
    {
        $span = $this->tools[$tool] ?? throw new InvalidArgumentException("client capability table: no tool named {$tool}");
        if ($argument === null) {
            return $span;
        }

        return $span['arguments'][$argument] ?? throw new InvalidArgumentException("client capability table: {$tool} has no argument named {$argument}");
    }

    /**
     * @param  array{since: string, removed_in: ?string}  $span
     */
    private function assertSpan(array $span, string $label): void
    {
        self::assertBare($span['since'], "{$label}.since");
        $this->assertNotAhead($span['since'], "{$label}.since");
        if ($span['removed_in'] !== null) {
            self::assertBare($span['removed_in'], "{$label}.removed_in");
            $this->assertNotAhead($span['removed_in'], "{$label}.removed_in");
            if (ChannelSnapshotManifest::compareVersions($span['removed_in'], $span['since']) <= 0) {
                throw new UnexpectedValueException("client capability table: {$label} is removed ({$span['removed_in']}) no later than it is added ({$span['since']})");
            }
        }
    }

    private function assertNotAhead(string $version, string $label): void
    {
        if (ChannelSnapshotManifest::compareVersions($version, $this->currentClientVersion) > 0) {
            throw new UnexpectedValueException("client capability table: {$label} is {$version}, newer than the current client {$this->currentClientVersion}");
        }
    }

    private static function assertBare(string $version, string $label): void
    {
        if (preg_match(self::BARE_VERSION, $version) !== 1) {
            throw new UnexpectedValueException("client capability table: {$label} is \"{$version}\", not a bare X.Y.Z version — the comparator reads a chunk with no leading digit as 0, so it would be misordered");
        }
    }

    /**
     * @return array{since: string, removed_in: ?string, arguments?: array<string, array{since: string, removed_in: ?string}>}
     */
    private static function span(mixed $raw, string $label, bool $withArguments): array
    {
        if (! is_array($raw) || ! is_string($raw['since'] ?? null) || ! array_key_exists('removed_in', $raw) || ! (is_string($raw['removed_in']) || $raw['removed_in'] === null)) {
            throw new UnexpectedValueException("client capability table: {$label} has no string since and string-or-null removed_in");
        }
        $span = ['since' => $raw['since'], 'removed_in' => $raw['removed_in']];
        if ($withArguments) {
            if (! is_array($raw['arguments'] ?? null)) {
                throw new UnexpectedValueException("client capability table: {$label} has no arguments object");
            }
            $span['arguments'] = [];
            foreach ($raw['arguments'] as $argument => $argSpan) {
                $span['arguments'][(string) $argument] = self::span($argSpan, "{$label}.{$argument}", false);
            }
        }

        return $span;
    }
}
