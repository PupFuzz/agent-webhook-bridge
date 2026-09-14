<?php

namespace Tests\Feature\Support;

use App\Bridge\Support\SecretScrubber;
use Illuminate\Http\Client\RequestException;
use Tests\Support\SourceScan;
use Tests\TestCase;

/**
 * Every place in `app/` that reads an exception's TEXT has a ruling: the text goes through
 * `App\Bridge\Support\RedactedErrorText::of()`, or the exception provably cannot be a
 * `RequestException` (card#9486, DL-389).
 *
 * ⛔ WHY. A `RequestException`'s message carries a body summary already cut at
 * `RequestException::$truncateAt`. Relayed raw, it puts an upstream body into a log, a DB column
 * or an operator's terminal UNREDACTED; relayed through a redactor, a credential cut at that
 * truncation still survives. Both are the same defect, so the population is every READ of the
 * text, redactor or not.
 *
 * THE POPULATION, derived by {@see self::siteAt()} over the whole of `app/`:
 *  - every `->getMessage()` / `?->getMessage()` / `->__toString()` call, whatever it is called on
 *    (so `$e->getPrevious()->getMessage()` is a site);
 *  - a `(string) $x` cast, or an array value `=> $x` (a log context `'exception' => $e`, which the
 *    log formatter renders with its raw message), where `$x` is bound by an enclosing `catch` or
 *    by a parameter of the enclosing function typed `Throwable`/`Exception`.
 *
 * THE RULING, per site, is one of two:
 *  - {@see self::CATCH_EXCLUDES_REQUEST_EXCEPTION} — CHECKED, not trusted: the site sits inside
 *    a `catch` whose every declared type resolves to a class `RequestException` is not an
 *    instance of. What bounds such a message is stated per TYPE in {@see self::TYPE_BOUNDS},
 *    which must name every type the rulings rely on.
 *  - a reason string — for a site with no such `catch`. Only the primitive's own read holds one.
 * Migrating a site removes it from the population: `RedactedErrorText::of($e)` reads no text here.
 *
 * ⚠ WHAT IT DOES NOT SEE, stated so a green is not read as more:
 *  - An exception object handed on WITHOUT an arrow or a cast — `throw $e`, `previous: $e`,
 *    `report($e)`, an argument to a method of ours. A rethrow that escapes to Laravel's exception
 *    handler is logged by the FRAMEWORK with its raw message, outside `app/`.
 *  - An exception in a variable that is neither a catch binding nor a typed parameter
 *    (`$err = $e; … 'exception' => $err`), and string interpolation of the object itself.
 *  - A catch type is resolved through the file's own `use` imports and namespace; a type that does
 *    not resolve to a loadable class fails the check rather than passing it.
 */
class ExceptionMessageRedactionCensusTest extends TestCase
{
    private const CATCH_EXCLUDES_REQUEST_EXCEPTION = true;

    /**
     * What bounds the text of each exception type a {@see self::CATCH_EXCLUDES_REQUEST_EXCEPTION}
     * ruling relies on. Keyed by FQCN.
     *
     * @var array<class-string, string>
     */
    private const TYPE_BOUNDS = [
        'App\Bridge\Exceptions\ChannelTokenException' => 'a local channel-token read fault, composed by ChannelToken from a path and a file-read fault',
        'App\Bridge\Exceptions\ConfigException' => 'composed by this app from its own config files and env; a wrap site that builds one from another exception is itself a site here',
        'App\Bridge\Exceptions\InsecureSecretPermsException' => 'a local secret-file mode check: the path and its octal mode',
        'App\Bridge\Exceptions\ToolRefusalException' => 'composed by a board tool from its own refusal vocabulary',
        'App\Bridge\Exceptions\UnreadableFileException' => 'a local file read fault: a path plus PHP\'s filesystem warning',
        'App\Bridge\Exceptions\UnreadableSecretException' => 'a local secret-file read fault: the path this install configured plus PHP\'s filesystem warning',
        'App\Bridge\Scheduling\JobSpecException' => 'composed by JobSpec from options the operator typed',
        'App\Bridge\Validation\EndpointValidationException' => 'composed by this app\'s own endpoint validators from a configured URL or socket path',
        'Error' => 'a PHP engine error (ArgumentCountError from `new $class`) about a classifier class this install configured',
        'Illuminate\Database\QueryException' => 'a PDO/driver error from this install\'s own database, with the SQL this app wrote',
        'Symfony\Component\Yaml\Exception\ParseException' => 'the YAML parser\'s position and snippet of this install\'s own agent config file',
    ];

    /**
     * EVERY derived site → {@see self::CATCH_EXCLUDES_REQUEST_EXCEPTION}, or the reason a site with
     * no such catch reads the text raw.
     *
     * @var array<string, true|string>
     */
    private const RULINGS = [
        'Bridge/Check/Checks/BoardToolsHttpProbeCheck.php::run#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Check/Checks/ChannelTransportCheck.php::markerLeg#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Check/Checks/WritebackAlertChannelCheck.php::run#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Handlers/ChannelPushHandler.php::handle#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Handlers/ChannelPushHandler.php::validateLocalhostUrl#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Handlers/ChannelPushHandler.php::validateSocketPath#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/IdleNudge/IdleNudgeConfig.php::fromConfig#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Provision/WebhookProvisioner.php::ensureMatchedSubscription#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Provision/WritebackIdentityOffer.php::compareOtherTokens#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Provision/WritebackIdentityOffer.php::prepare#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Provision/WritebackIdentityOffer.php::prepare#2' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Scheduling/Handlers/IdleNudgeJob.php::unseenLines#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/AgentConfig.php::fromArray#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/AgentConfig.php::load#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/AgentRegistry.php::readSharedIdentities#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/BoardToolsConfig.php::fromArray#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/ChannelToken.php::read#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        // `ClassifierResolver::for()`: a method named `for` tokenizes as T_FOR, so `SourceScan` keys its arm to the file scope.
        'Bridge/Support/ClassifierResolver.php::(file scope)#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Support/RedactedErrorText.php::of#1' => 'the primitive\'s own non-RequestException branch — the one read that is the redaction, not a relay of it',
        'Bridge/Support/TokenFile.php::readTrimmed#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardCorrectCardTool.php::installHoldTags#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardToolAgentResolver.php::readToken#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardToolAgentResolver.php::readToken#2' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardToolDispatcher.php::dispatch#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardToolDispatcher.php::dispatch#2' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/BoardToolDispatcher.php::dispatch#3' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Tools/SeatKanbanUser.php::lookup#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Writeback/WritebackAlertNotifier.php::push#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Writeback/WritebackClientFactory.php::make#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Bridge/Writeback/WritebackConfig.php::load#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/BridgeCommand.php::guardDatabase#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/BridgeCommand.php::guardDatabase#2' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/JobsCommand.php::add#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ProvisionCommand.php::handle#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ProvisionCommand.php::handle#2' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ProvisionCommand.php::receiverBaseRefusal#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ProvisionToolsCommand.php::handle#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ReconcileCommand.php::handle#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ReplayCommand.php::handleGuarded#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/SignCommand.php::body#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
        'Console/Commands/Bridge/ToolsCallCommand.php::handle#1' => self::CATCH_EXCLUDES_REQUEST_EXCEPTION,
    ];

    public function test_every_read_of_an_exceptions_text_in_app_carries_a_ruling(): void
    {
        $found = SourceScan::sitesInApp(self::siteAt(...));
        ksort($found);
        $ruled = self::RULINGS;
        ksort($ruled);

        $this->assertSame(
            array_keys($ruled),
            array_keys($found),
            'the set of places in app/ that read an exception\'s text is not the set this class has a ruling for. '
            .'A NEW site routes the text through RedactedErrorText::of($e) — a RequestException message carries a '
            .'response-body summary already truncated, so relaying it raw or redacting it afterwards can leak a '
            .'credential — or sits in a catch that cannot hold a RequestException and is ruled so. A ruling whose '
            .'site is gone is stale: delete it.',
        );
    }

    public function test_each_ruling_matches_what_its_site_catches(): void
    {
        $relied = [];
        foreach (SourceScan::sitesInApp(self::siteAt(...)) as $site => $binding) {
            $ruling = self::RULINGS[$site] ?? null;
            if ($ruling === null) {
                continue;   // the set leg above owns an unruled site
            }
            if ($ruling !== self::CATCH_EXCLUDES_REQUEST_EXCEPTION) {
                $this->assertNotSame('', trim($ruling), "{$site} has an empty reason.");
                $this->assertNotSame('catch', $binding['by'], "{$site} sits in a catch — rule it by its catch types, or migrate it.");

                continue;
            }
            $this->assertSame('catch', $binding['by'], "{$site} is ruled by its catch, but reads text from a variable no catch binds — migrate it to RedactedErrorText::of().");
            foreach ($binding['types'] as $type) {
                $this->assertTrue(self::excludesRequestException($type), "{$site} is ruled as unable to hold a RequestException, but it catches {$type}, which can — migrate it to RedactedErrorText::of().");
                $relied[$type] = true;
            }
        }

        ksort($relied);
        $bounds = self::TYPE_BOUNDS;
        ksort($bounds);
        $this->assertSame(array_keys($bounds), array_keys($relied), 'every exception type a ruling relies on names what bounds its text, and no bound is kept for a type nothing relies on.');
    }

    /**
     * The instrument's control, on a fixture whose answer is known: both site shapes and both
     * bindings found; prose, strings, a migrated read and an unbound value skipped; types resolved
     * through the file's imports.
     */
    public function test_the_scanner_finds_every_read_and_resolves_its_binding(): void
    {
        $source = <<<'PHP'
        <?php
        namespace App\Fixture;

        use App\Bridge\Exceptions\ConfigException as Cfg;
        use Throwable;

        class Fixture
        {
            /** A docblock naming $e->getMessage() and 'exception' => $e. */
            public function reads(): void
            {
                try {
                } catch (Cfg $e) {
                    // $e->getMessage()
                    $a = $e->getMessage();
                } catch (Throwable $e) {
                    Log::warning('x', ['exception' => $e, 'm' => RedactedErrorText::of($e), 'c' => $e::class]);
                    $b = (string) $e;
                    $c = '$e->getMessage()';
                }
            }

            public function passed(\Exception $e, string $s): string
            {
                $this->x(['e' => $e, 's' => $s]);

                return $e?->getPrevious()?->__toString() ?? (string) $s;
            }
        }
        PHP;

        $sites = SourceScan::sites($source, 'Fixture.php', self::siteAt(...));

        $this->assertSame(
            [
                'Fixture.php::reads#1' => ['by' => 'catch', 'types' => ['App\Bridge\Exceptions\ConfigException']],
                'Fixture.php::reads#2' => ['by' => 'catch', 'types' => ['Throwable']],
                'Fixture.php::reads#3' => ['by' => 'catch', 'types' => ['Throwable']],
                'Fixture.php::passed#1' => ['by' => 'param', 'types' => ['Exception']],
                'Fixture.php::passed#2' => ['by' => 'none', 'types' => []],
            ],
            $sites,
        );
        $this->assertTrue(self::excludesRequestException('App\Bridge\Exceptions\ConfigException'));
        $this->assertFalse(self::excludesRequestException('Throwable'));
        $this->assertFalse(self::excludesRequestException('Exception'));
        $this->assertFalse(self::excludesRequestException(RequestException::class));
        $this->assertFalse(self::excludesRequestException('App\Fixture\NoSuchClass'), 'an unresolvable type must fail the check');
        $this->assertTrue(self::excludesRequestException(SecretScrubber::class));
    }

    private static function excludesRequestException(string $type): bool
    {
        return (class_exists($type) || interface_exists($type)) && ! is_a(RequestException::class, $type, true);
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return array{by: 'catch'|'param'|'none', types: list<string>}|null
     */
    private static function siteAt(array $tokens, int $i, int $scopeStart): ?array
    {
        $token = $tokens[$i];
        if ($token[0] === T_STRING && in_array($token[1], ['getMessage', '__toString'], true)
            && in_array($tokens[$i - 1][0] ?? null, [T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR], true)
            && ($tokens[$i + 1][1] ?? null) === '(') {
            $var = ($tokens[$i - 2][0] ?? null) === T_VARIABLE ? $tokens[$i - 2][1] : null;

            return self::binding($tokens, $i, $scopeStart, $var) ?? ['by' => 'none', 'types' => []];
        }

        $isValue = $token[0] === T_DOUBLE_ARROW
            && ! in_array($tokens[$i + 2][1] ?? null, ['->', '?->', '::', '['], true);
        if (($isValue || $token[0] === T_STRING_CAST) && ($tokens[$i + 1][0] ?? null) === T_VARIABLE) {
            return self::binding($tokens, $i, $scopeStart, $tokens[$i + 1][1]);
        }

        return null;
    }

    /**
     * The catch that binds $var around $i, else a `Throwable`/`Exception`-typed parameter of the
     * enclosing function; `null` when neither does.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return array{by: 'catch'|'param', types: list<string>}|null
     */
    private static function binding(array $tokens, int $i, int $scopeStart, ?string $var): ?array
    {
        if ($var === null) {
            return null;
        }

        for ($j = $i; $j >= $scopeStart; $j--) {
            if ($tokens[$j][0] !== T_CATCH) {
                continue;
            }
            [$names, $k] = self::typeList($tokens, $j + 2);
            if (($tokens[$k][0] ?? null) !== T_VARIABLE || $tokens[$k][1] !== $var || ($tokens[$k + 2][1] ?? null) !== '{') {
                continue;
            }
            $depth = 0;
            for ($m = $k + 2; isset($tokens[$m]); $m++) {
                $depth += $tokens[$m][1] === '{' ? 1 : ($tokens[$m][1] === '}' ? -1 : 0);
                if ($depth === 0) {
                    break;
                }
            }
            if ($i < $m) {
                return ['by' => 'catch', 'types' => array_map(static fn (string $n): string => self::resolve($tokens, $n), $names)];
            }
        }

        if (($tokens[$scopeStart][0] ?? null) === T_FUNCTION) {
            for ($j = $scopeStart; isset($tokens[$j]) && $tokens[$j][1] !== '{' && $j < $i; $j++) {
                if ($tokens[$j][0] === T_VARIABLE && $tokens[$j][1] === $var) {
                    $type = self::resolve($tokens, $tokens[$j - 1][1] ?? '');
                    if (in_array($type, ['Throwable', 'Exception'], true)) {
                        return ['by' => 'param', 'types' => [$type]];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @param  list<array{0: int|string, 1: string}>  $tokens
     * @return array{0: list<string>, 1: int}
     */
    private static function typeList(array $tokens, int $k): array
    {
        $names = [];
        while (isset($tokens[$k]) && $tokens[$k][0] !== T_VARIABLE && $tokens[$k][1] !== ')') {
            if ($tokens[$k][1] !== '|') {
                $names[] = $tokens[$k][1];
            }
            $k++;
        }

        return [$names, $k];
    }

    /**
     * A class name as written in the file, resolved through its `namespace` and top-level `use`.
     *
     * @param  list<array{0: int|string, 1: string}>  $tokens
     */
    private static function resolve(array $tokens, string $name): string
    {
        if (str_starts_with($name, '\\')) {
            return substr($name, 1);
        }

        $namespace = '';
        $imports = [];
        foreach ($tokens as $n => $token) {
            if (in_array($token[0], [T_CLASS, T_TRAIT, T_INTERFACE, T_ENUM], true)) {
                break;
            }
            if ($token[0] === T_NAMESPACE) {
                $namespace = $tokens[$n + 1][1];
            }
            if ($token[0] === T_USE) {
                $fqcn = $tokens[$n + 1][1];
                $alias = ($tokens[$n + 2][0] ?? null) === T_AS ? $tokens[$n + 3][1] : substr((string) strrchr('\\'.$fqcn, '\\'), 1);
                $imports[$alias] = $fqcn;
            }
        }

        $head = explode('\\', $name)[0];
        if (isset($imports[$head])) {
            return $imports[$head].substr($name, strlen($head));
        }

        return $namespace === '' ? $name : $namespace.'\\'.$name;
    }
}
