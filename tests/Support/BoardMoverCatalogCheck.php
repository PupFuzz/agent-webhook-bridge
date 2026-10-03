<?php

namespace Tests\Support;

use App\Bridge\Contracts\DurableReaction;
use App\Bridge\Contracts\EmitsWritebackReactions;
use App\Bridge\Writeback\BoardMoverScope;
use App\Bridge\Writeback\WritebackAlertNotifier;
use App\Bridge\Writeback\WriteOp;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use ReflectionClass;
use ReflectionMethod;
use ReflectionParameter;

/**
 * The CHECK half of the board-mover catalog (`docs/board-mover-catalog.json`): every log row the
 * board-mover population emits carries a `catalog_id` the catalog declares, and every live catalog
 * entry is emitted at the site it names. `docs/writeback.md` § *The board-mover catalog* owns what
 * the catalog is and who reads it; this class owns how that claim is checked.
 *
 * ⭐ THE POPULATION IS DERIVED FROM THE TREE EVERY RUN, NEVER LISTED. A class is in it when it is
 * (a) in the `App\Bridge\Writeback` namespace — the library every board write goes through,
 * (b) a handler implementing {@see DurableReaction} — the handlers whose side effect is a durable
 * write, which is exactly the writeback set, or (c) a classifier implementing
 * {@see EmitsWritebackReactions} — the correlation step that decides WHICH card a move is about.
 * A new writeback handler or classifier joins by declaring what it is, with no edit here.
 *
 * A SITE is every `Log::<level>(…)` call in such a class, at every level, plus every call to a
 * {@see WritebackAlertNotifier} method that takes a `$catalogId` (the paired log+alert helpers,
 * found by reflection, so a new helper joins the same way), spelled `->` or `?->`. The helpers' own
 * `Log::warning` is the mechanism, not a site: it logs the id its caller passed.
 *
 * ⛔ A ROW WRITTEN ANY OTHER WAY IS NOT SEEN, AND NOTHING REDS ON IT: the `logger()` helper, a
 * logger instance held in a variable or property (injected, or resolved from the container), or a
 * helper reached through a variable method name. Such a row carries no id and the check stays
 * green. `docs/writeback.md` § *What the check cannot verify* publishes the same bound to the
 * catalog's readers.
 *
 * A helper call whose `$reason` is the literal {@see self::UNCONFIGURED_REASON} is the
 * no-`writeback.json` arm. The notifier loads its `alert_channel` from that same missing file, so the
 * alert cannot fire there (`docs/writeback.md` § *Branch-#3 degradation*), and its entry must not
 * declare `alert_channel`. The check knows the arm by that `reason` literal only; an arm that
 * cannot reach a channel for any other reason is not detected.
 *
 * ⭐ EVERY SITE ALSO SPELLS `handler` AND `op` (card#11223), in the context it logs — the second
 * argument of a `Log::` call, the `$logContext` argument of a helper. `handler` is always
 * {@see BoardMoverScope::handler()}, never a typed name: a shared site logs for whichever handler
 * called it, so only the runtime scope knows. `op` is a string literal the catalog's `ops` declares
 * where the site's write is the same on every path, or {@see BoardMoverScope::op()} where it
 * depends on the caller. `undeclared` is the scope's value when nobody declared one, so it is never
 * a literal. The check sees the keys, not the values they take at run time: that the scope is set
 * where a handler runs is `BoardMoverScopeTest`'s to show.
 *
 * ⛔ EVERY LEVEL, NOT ONLY WARNINGS, AND THAT IS WHAT MAKES THE POPULATION DERIVABLE. "A warning or
 * a refusal" cannot be decided from source at `Log::info`: a refusal written at info level
 * (`refusing to re-lane`) and a success row (`moved`) share a level, and telling them apart would
 * take a hand-typed list — the drift this whole check exists to remove.
 *
 * @phpstan-type Site array{site: string, where: string, via: string, id: ?string, problem: ?string, handler_problem: ?string, op: ?string, op_problem: ?string}
 */
final class BoardMoverCatalogCheck
{
    public const CATALOG = 'docs/board-mover-catalog.json';

    public const CONTEXT_KEY = 'catalog_id';

    public const HANDLER_KEY = 'handler';

    public const OP_KEY = 'op';

    /** A site's `op` when it is read from the scope at run time rather than written as a literal. */
    public const OP_RUNTIME = '<runtime>';

    /** The catalog surfaces a site can be checked against. */
    public const SURFACE_LOG = 'log';

    public const SURFACE_ALERT = 'alert_channel';

    /** How a helper call on the no-`writeback.json` arm emits: through the helper, to the log only. */
    public const VIA_UNCONFIGURED = 'unconfigured';

    /** The helper `$reason` every handler's no-`writeback.json` arm sends. */
    public const UNCONFIGURED_REASON = 'writeback_not_configured';

    /** `<owner>.<slug>` — lower snake case, dotted once or more. */
    private const ID_PATTERN = '/\A[a-z][a-z0-9_]*(\.[a-z0-9_]+)+\z/';

    private const VERSION_PATTERN = '/\A\d+\.\d+\.\d+\z/';

    private const LOG_FACADES = ['Illuminate\Support\Facades\Log', 'Log'];

    /**
     * The `.php` files under `app/` whose class is in the board-mover population.
     *
     * @return list<string>
     */
    public static function populationFiles(): array
    {
        $files = [];
        foreach (SourceScan::appFiles() as $path) {
            foreach (self::classNames((string) file_get_contents($path)) as $class) {
                if (self::inPopulation($class)) {
                    $files[] = $path;
                    break;
                }
            }
        }

        return $files;
    }

    public static function inPopulation(string $class): bool
    {
        if (str_starts_with($class, 'App\\Bridge\\Writeback\\')) {
            return true;
        }
        if (! class_exists($class)) {
            return false;
        }
        $reflection = new ReflectionClass($class);

        return $reflection->implementsInterface(DurableReaction::class)
            || $reflection->implementsInterface(EmitsWritebackReactions::class);
    }

    /**
     * The notifier methods a caller hands a catalog id to — every public method of
     * {@see WritebackAlertNotifier} with a `$catalogId` parameter — each mapped to the positions of
     * its `$reason` and `$logContext` parameters, each null when it has none.
     *
     * @return array<string, array{reason: ?int, context: ?int}>
     */
    public static function notifierMethods(): array
    {
        $methods = [];
        foreach ((new ReflectionClass(WritebackAlertNotifier::class))->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $names = array_map(fn (ReflectionParameter $p) => $p->getName(), $method->getParameters());
            if (in_array('catalogId', $names, true)) {
                $reason = array_search('reason', $names, true);
                $context = array_search('logContext', $names, true);
                $methods[$method->getName()] = ['reason' => is_int($reason) ? $reason : null, 'context' => is_int($context) ? $context : null];
            }
        }
        ksort($methods);

        return $methods;
    }

    /**
     * Every site in the population, from the real tree.
     *
     * @return list<Site>
     */
    public static function treeSites(): array
    {
        $sites = [];
        foreach (self::populationFiles() as $path) {
            $sites = array_merge($sites, self::sitesIn(
                (string) file_get_contents($path),
                SourceScan::relativeToApp($path),
                self::inPopulation(...),
                WritebackAlertNotifier::class,
                self::notifierMethods(),
            ));
        }

        return $sites;
    }

    /**
     * The sites in one source file. `site` is the enclosing `ShortClass::method`; a closure's call
     * belongs to the method that contains it. `id` is the literal catalog id, or null with
     * `problem` naming why there is none. `handler_problem` is null when the context spells
     * `handler` as the scope read, else why it does not; `op` is the literal, or
     * {@see self::OP_RUNTIME} for the scope read, or null with `op_problem` saying why.
     *
     * @param  callable(string): bool  $inPopulation
     * @param  array<string, array{reason: ?int, context: ?int}>  $notifierMethods  helper name => positions of its `$reason` and `$logContext` parameters
     * @return list<Site>
     */
    public static function sitesIn(string $source, string $file, callable $inPopulation, string $notifierClass, array $notifierMethods): array
    {
        $ast = self::parse($source);
        $finder = new NodeFinder;
        $sites = [];

        /** @var list<Node\Stmt\ClassLike> $classes */
        $classes = $finder->findInstanceOf($ast, Node\Stmt\ClassLike::class);
        foreach ($classes as $class) {
            $fqcn = $class->namespacedName?->toString();
            if ($fqcn === null || ! $inPopulation($fqcn)) {
                continue;
            }
            foreach ($class->getMethods() as $method) {
                $site = $class->name?->toString().'::'.$method->name->toString();
                $isHelper = $fqcn === $notifierClass && array_key_exists($method->name->toString(), $notifierMethods);

                foreach ($finder->find($method->stmts ?? [], fn (Node $n) => $n instanceof Expr\StaticCall || $n instanceof Expr\MethodCall || $n instanceof Expr\NullsafeMethodCall) as $call) {
                    $where = $file.':'.$call->getStartLine();
                    if ($call instanceof Expr\StaticCall && self::isLogCall($call)) {
                        if ($isHelper) {
                            continue;
                        }
                        [$id, $problem] = self::logCallId($call);
                        $sites[] = ['site' => $site, 'where' => $where, 'via' => self::SURFACE_LOG, 'id' => $id, 'problem' => $problem]
                            + self::runtimeKeys($call->getArgs()[1] ?? null, 'the call passes no context array');

                        continue;
                    }
                    if (($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall) && $call->name instanceof Node\Identifier
                        && array_key_exists($call->name->toString(), $notifierMethods)) {
                        $positions = $notifierMethods[$call->name->toString()];
                        [$id, $problem] = self::notifierCallId($call, $positions['context']);
                        $via = self::isUnconfiguredArm($call, $positions['reason']) ? self::VIA_UNCONFIGURED : self::SURFACE_ALERT;
                        $sites[] = ['site' => $site, 'where' => $where, 'via' => $via, 'id' => $id, 'problem' => $problem]
                            + self::runtimeKeys(
                                self::argAt($call, $positions['context'], 'logContext'),
                                $positions['context'] === null ? 'the helper takes no `$logContext`, so its row cannot carry the key' : 'the call passes no `$logContext`',
                            );
                    }
                }
            }
        }

        return $sites;
    }

    /**
     * Every disagreement between the sites and the catalog, one line each. Empty is clean.
     *
     * @param  list<Site>  $sites
     * @param  array<string, mixed>  $catalog  the decoded catalog document
     * @return list<string>
     */
    public static function findings(array $sites, array $catalog): array
    {
        $out = [];
        if (($catalog['schema'] ?? null) !== 1) {
            $out[] = 'CATALOG_SCHEMA: `schema` is not 1';
        }
        if (($catalog['context_key'] ?? null) !== self::CONTEXT_KEY) {
            $out[] = 'CATALOG_SCHEMA: `context_key` is not `'.self::CONTEXT_KEY.'`';
        }
        if (($catalog['handler_key'] ?? null) !== self::HANDLER_KEY) {
            $out[] = 'CATALOG_SCHEMA: `handler_key` is not `'.self::HANDLER_KEY.'`';
        }
        if (($catalog['op_key'] ?? null) !== self::OP_KEY) {
            $out[] = 'CATALOG_SCHEMA: `op_key` is not `'.self::OP_KEY.'`';
        }
        $ops = is_array($catalog['ops'] ?? null) ? $catalog['ops'] : [];
        $declared = array_map(fn (WriteOp $op) => $op->value, WriteOp::cases());
        foreach (array_diff($declared, array_keys($ops)) as $missing) {
            $out[] = "OPS_MISMATCH: `{$missing}` is a WriteOp case the catalog's `ops` does not declare";
        }
        foreach (array_diff(array_keys($ops), $declared) as $extra) {
            $out[] = "OPS_MISMATCH: `{$extra}` is declared in the catalog's `ops` and is no WriteOp case";
        }
        foreach ($ops as $op => $about) {
            if (! is_string($about) || trim($about) === '') {
                $out[] = "OPS_MISMATCH: `{$op}` has no description";
            }
        }
        $kinds = is_array($catalog['kinds'] ?? null) ? $catalog['kinds'] : [];
        $entries = is_array($catalog['entries'] ?? null) ? $catalog['entries'] : [];
        if ($entries === []) {
            $out[] = 'CATALOG_EMPTY: the catalog declares no entries — nothing was checked';
        }

        /** @var array<string, array<string, mixed>> $byId */
        $byId = [];
        foreach ($entries as $i => $entry) {
            if (! is_array($entry)) {
                $out[] = "ENTRY_SCHEMA: entries[{$i}] is not an object";

                continue;
            }
            $id = $entry['id'] ?? null;
            if (! is_string($id) || preg_match(self::ID_PATTERN, $id) !== 1) {
                $out[] = "ENTRY_SCHEMA: entries[{$i}] has no well-formed `id`";

                continue;
            }
            if (isset($byId[$id])) {
                $out[] = "DUPLICATE_ENTRY_ID: `{$id}` is declared more than once";

                continue;
            }
            $byId[$id] = $entry;
            foreach (self::schemaProblems($entry, $kinds) as $problem) {
                $out[] = "ENTRY_SCHEMA: `{$id}` {$problem}";
            }
        }

        /** @var array<string, list<Site>> $usedAt */
        $usedAt = [];
        foreach ($sites as $site) {
            if ($site['handler_problem'] !== null) {
                $out[] = "SITE_WITHOUT_HANDLER: {$site['site']} ({$site['where']}) — {$site['handler_problem']}";
            }
            if ($site['op'] === null) {
                $out[] = "SITE_WITHOUT_OP: {$site['site']} ({$site['where']}) — {$site['op_problem']}";
            } elseif ($site['op'] === WriteOp::Undeclared->value) {
                $out[] = "OP_LITERAL_UNDECLARED: {$site['site']} ({$site['where']}) — `undeclared` is the scope's value when no write-kind was declared; a site writes the op it is about, or reads the scope";
            } elseif ($site['op'] !== self::OP_RUNTIME && ! array_key_exists($site['op'], $ops)) {
                $out[] = "OP_NOT_IN_CATALOG: `{$site['op']}` at {$site['site']} ({$site['where']}) is not an op the catalog declares";
            }
            if ($site['id'] === null) {
                $out[] = "SITE_WITHOUT_ID: {$site['site']} ({$site['where']}) — {$site['problem']}";

                continue;
            }
            $usedAt[$site['id']][] = $site;
            $entry = $byId[$site['id']] ?? null;
            if ($entry === null) {
                $out[] = "ID_NOT_IN_CATALOG: `{$site['id']}` at {$site['site']} ({$site['where']}) is not a catalog entry";

                continue;
            }
            if (isset($entry['retired_since'])) {
                $out[] = "RETIRED_ID_IN_USE: `{$site['id']}` at {$site['site']} ({$site['where']}) is retired since {$entry['retired_since']}";
            }
        }

        foreach ($usedAt as $id => $uses) {
            if (count($uses) > 1) {
                $out[] = "ID_USED_AT_MULTIPLE_SITES: `{$id}` is emitted at ".implode(', ', array_map(fn (array $u) => "{$u['site']} ({$u['where']})", $uses));
            }
        }

        foreach ($byId as $id => $entry) {
            if (isset($entry['retired_since'])) {
                continue;
            }
            $uses = $usedAt[$id] ?? [];
            if ($uses === []) {
                $site = is_string($entry['site'] ?? null) ? $entry['site'] : '?';
                $out[] = "ENTRY_WITHOUT_SITE: `{$id}` names {$site}, and no site in the population emits it — retire it (`retired_since`) or restore the site";

                continue;
            }
            $use = $uses[0];
            if (($entry['site'] ?? null) !== $use['site']) {
                $declared = is_string($entry['site'] ?? null) ? $entry['site'] : '?';
                $out[] = "ENTRY_SITE_MISMATCH: `{$id}` declares {$declared} but is emitted at {$use['site']} ({$use['where']})";
            }
            $declaresAlert = in_array(self::SURFACE_ALERT, is_array($entry['surface'] ?? null) ? $entry['surface'] : [], true);
            $mismatch = match ($use['via']) {
                self::SURFACE_ALERT => $declaresAlert ? null : 'is emitted through the paired log+alert helper, so its surface must include `alert_channel`',
                self::SURFACE_LOG => $declaresAlert ? 'is a plain log call, so its surface must not include `alert_channel`' : null,
                self::VIA_UNCONFIGURED => $declaresAlert ? 'is emitted through the paired helper on the no-`writeback.json` arm, where no `alert_channel` can load (docs/writeback.md § Branch-#3 degradation), so its surface must not include `alert_channel`' : null,
            };
            if ($mismatch !== null) {
                $out[] = "SURFACE_MISMATCH: `{$id}` {$mismatch}";
            }
        }

        return $out;
    }

    /**
     * @param  array<string, mixed>  $entry
     * @param  array<mixed>  $kinds
     * @return list<string>
     */
    private static function schemaProblems(array $entry, array $kinds): array
    {
        $problems = [];
        foreach (array_keys($entry) as $key) {
            if (! in_array($key, ['id', 'kind', 'surface', 'site', 'since', 'retired_since'], true)) {
                $problems[] = "carries an undeclared field `{$key}`";
            }
        }
        if (! is_string($entry['kind'] ?? null) || ! array_key_exists($entry['kind'], $kinds)) {
            $problems[] = 'has a `kind` the catalog\'s `kinds` does not declare';
        }
        $surface = $entry['surface'] ?? null;
        if (! is_array($surface) || ! in_array(self::SURFACE_LOG, $surface, true)
            || array_diff($surface, [self::SURFACE_LOG, self::SURFACE_ALERT]) !== []
            || count(array_unique($surface)) !== count($surface)) {
            $problems[] = '`surface` must be a list containing `log` and otherwise only `alert_channel`';
        }
        if (! is_string($entry['site'] ?? null) || preg_match('/\A\w+::\w+\z/', $entry['site']) !== 1) {
            $problems[] = '`site` must be `Class::method`';
        }
        if (! is_string($entry['since'] ?? null) || preg_match(self::VERSION_PATTERN, $entry['since']) !== 1) {
            $problems[] = '`since` must be a release version X.Y.Z';
        }
        if (array_key_exists('retired_since', $entry)
            && (! is_string($entry['retired_since']) || preg_match(self::VERSION_PATTERN, $entry['retired_since']) !== 1)) {
            $problems[] = '`retired_since` must be a release version X.Y.Z';
        }

        return $problems;
    }

    private static function isLogCall(Expr\StaticCall $call): bool
    {
        return $call->class instanceof Node\Name && in_array($call->class->toString(), self::LOG_FACADES, true);
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function logCallId(Expr\StaticCall $call): array
    {
        $context = $call->getArgs()[1] ?? null;
        if ($context === null) {
            return [null, 'the call passes no context array'];
        }
        $value = self::contextValue($context->value);
        if ($value === null) {
            return [null, 'no `'.self::CONTEXT_KEY.'` key in a literal context array'];
        }
        if (! $value instanceof Node\Scalar\String_) {
            return [null, '`'.self::CONTEXT_KEY.'` is not a string literal'];
        }

        return [$value->value, null];
    }

    /**
     * @return array{0: ?string, 1: ?string}
     */
    private static function notifierCallId(Expr\MethodCall|Expr\NullsafeMethodCall $call, ?int $contextPosition): array
    {
        $args = $call->getArgs();
        $first = $args[0] ?? null;
        if ($first === null || $first->name !== null || ! $first->value instanceof Node\Scalar\String_) {
            return [null, 'the first argument to the notifier is not a string-literal catalog id'];
        }
        $logContext = self::argAt($call, $contextPosition, 'logContext');
        if ($logContext !== null && self::contextValue($logContext->value) !== null) {
            return [null, 'the log context also carries `'.self::CONTEXT_KEY.'` — the helper adds it; declare it once'];
        }

        return [$first->value->value, null];
    }

    /**
     * Whether the context spells `handler` as the scope read, and what it spells `op` as.
     *
     * @return array{handler_problem: ?string, op: ?string, op_problem: ?string}
     */
    private static function runtimeKeys(?Node\Arg $context, string $absent): array
    {
        if ($context === null) {
            return ['handler_problem' => $absent, 'op' => null, 'op_problem' => $absent];
        }
        $handler = self::contextValue($context->value, self::HANDLER_KEY);
        $handlerProblem = match (true) {
            $handler === null => 'no `'.self::HANDLER_KEY.'` key in a literal context array',
            ! self::isScopeRead($handler, 'handler') => '`'.self::HANDLER_KEY.'` is not `BoardMoverScope::handler()` — a typed name is wrong at every shared site, so the scope is the only source',
            default => null,
        };

        $op = self::contextValue($context->value, self::OP_KEY);
        if ($op === null) {
            return ['handler_problem' => $handlerProblem, 'op' => null, 'op_problem' => 'no `'.self::OP_KEY.'` key in a literal context array'];
        }
        if ($op instanceof Node\Scalar\String_) {
            return ['handler_problem' => $handlerProblem, 'op' => $op->value, 'op_problem' => null];
        }
        if (self::isScopeRead($op, 'op')) {
            return ['handler_problem' => $handlerProblem, 'op' => self::OP_RUNTIME, 'op_problem' => null];
        }

        return ['handler_problem' => $handlerProblem, 'op' => null, 'op_problem' => '`'.self::OP_KEY.'` is neither a string literal nor `BoardMoverScope::op()`'];
    }

    private static function isScopeRead(Expr $expr, string $method): bool
    {
        return $expr instanceof Expr\StaticCall
            && $expr->class instanceof Node\Name && $expr->class->toString() === BoardMoverScope::class
            && $expr->name instanceof Node\Identifier && $expr->name->toString() === $method
            && $expr->getArgs() === [];
    }

    /** The argument a call passes for the parameter at $position, by name or by position. */
    private static function argAt(Expr\MethodCall|Expr\NullsafeMethodCall $call, ?int $position, string $name): ?Node\Arg
    {
        if ($position === null) {
            return null;
        }
        foreach ($call->getArgs() as $arg) {
            if ($arg->name?->toString() === $name) {
                return $arg;
            }
        }
        $positional = $call->getArgs()[$position] ?? null;

        return $positional?->name === null ? $positional : null;
    }

    /** Whether the helper call's `$reason` — named, or at its declared position — is the no-`writeback.json` literal. */
    private static function isUnconfiguredArm(Expr\MethodCall|Expr\NullsafeMethodCall $call, ?int $reasonPosition): bool
    {
        $reason = self::argAt($call, $reasonPosition, 'reason');

        return $reason !== null && $reason->value instanceof Node\Scalar\String_ && $reason->value->value === self::UNCONFIGURED_REASON;
    }

    /** The value under $key (the catalog id's by default) in a literal array, or in either operand of a `+` union. */
    private static function contextValue(Expr $expr, string $key = self::CONTEXT_KEY): ?Expr
    {
        if ($expr instanceof Expr\BinaryOp\Plus) {
            return self::contextValue($expr->left, $key) ?? self::contextValue($expr->right, $key);
        }
        if (! $expr instanceof Expr\Array_) {
            return null;
        }
        foreach ($expr->items as $item) {
            if ($item->key instanceof Node\Scalar\String_ && $item->key->value === $key) {
                return $item->value;
            }
        }

        return null;
    }

    /** @return list<string> */
    private static function classNames(string $source): array
    {
        $names = [];
        foreach ((new NodeFinder)->findInstanceOf(self::parse($source), Node\Stmt\ClassLike::class) as $class) {
            if ($class->namespacedName !== null) {
                $names[] = $class->namespacedName->toString();
            }
        }

        return $names;
    }

    /** @return list<Node\Stmt> */
    private static function parse(string $source): array
    {
        $ast = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];
        $traverser = new NodeTraverser(new NameResolver);

        /** @var list<Node\Stmt> */
        return $traverser->traverse($ast);
    }
}
