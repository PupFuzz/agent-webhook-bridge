# Conventions

Naming, layout, comment policy, and stylistic idioms for the bridge codebase. Patterns that "just work" because the codebase enforces them — diverging from these without a documented reason invites churn.

## File and directory layout

```
agent-webhook-bridge/
├── app/
│   ├── Bridge/                     # All bridge-specific PHP. Namespace root App\Bridge.
│   │   ├── Adapters/               # WebhookAdapter contract + per-provider impls (one class per file)
│   │   ├── Classifiers/            # Built-in classifier impls (InboxOnlyClassifier, EventDrivenClassifier)
│   │   ├── Contracts/              # Interfaces: Classifier, Handler, WebhookAdapter
│   │   ├── Dispatch/               # Core data shapes + DispatchService (the synchronous loop)
│   │   ├── Exceptions/             # App\Bridge\Exceptions\*Exception — all extend RuntimeException
│   │   ├── Handlers/               # Built-in handler impls (LogIntentHandler, ChannelPushHandler, …)
│   │   ├── Provision/              # Provisioning support (KanbanProvisionClient, …)
│   │   ├── Support/                # Config parsing, registries, guards, path helpers, validators
│   │   └── Validation/             # Format validators: ProviderName, ScopeId, SocketPath
│   ├── Console/Commands/Bridge/    # Artisan commands: bridge:* (one class per command)
│   ├── Http/
│   │   ├── Controllers/Webhook/    # WebhookController (the synchronous receiver entry point)
│   │   └── Middleware/             # VerifyHmacSignature, EnvelopeSizeLimit
│   └── Models/                     # Eloquent models: WebhookEvent, AgentDispatch
├── config/
│   └── bridge.php                  # Bridge runtime config (config_dir, secret_dir, state_dir, install_suffix)
├── database/migrations/            # Timestamped migration files (YYYY_MM_DD_NNNNNN_<name>.php)
├── docs/                           # Operator-audience documentation
├── examples/                       # Reference classifiers, handlers, sample config, channel servers
├── routes/
│   └── webhooks.php                # /webhooks/{provider} route + middleware stack
└── CLAUDE*.md                      # Indexed for AI-session retrieval. Root CLAUDE.md is the navigation map.
```

## PHP conventions

### Naming

| Construct | Convention | Example |
|---|---|---|
| Classes / interfaces | `PascalCase` | `DispatchService`, `Classifier`, `KanbanAdapter` |
| Methods / functions | `camelCase` | `actorFromEvent`, `dedupCreate`, `subscribedTo` |
| Properties / variables | `camelCase` | `$scopeId`, `$agentName`, `$selfIdentity` |
| Constants | `UPPER_SNAKE_CASE` | `KNOWN_TOP_LEVEL_KEYS`, `ADDITIONAL_CONTEXT_EVENTS`, `PATTERN` |
| Database columns / YAML keys | `snake_case` | `delivery_id`, `scope_id`, `echo_suppression` |
| Artisan command signatures | `bridge:<verb>` | `bridge:inbox`, `bridge:provision`, `bridge:replay` |
| Artisan command classes | `<Verb>Command` | `InboxCommand`, `ProvisionCommand`, `ReplayCommand` |
| Test classes | `<Subject>Test` | `DispatchServiceTest`, `KanbanAdapterTest` |

One class per file. The filename matches the class name exactly (PSR-4).

### Namespace layout

| Location | Namespace |
|---|---|
| `app/Bridge/<Subsystem>/` | `App\Bridge\<Subsystem>` |
| `app/Bridge/Contracts/` | `App\Bridge\Contracts` |
| `app/Bridge/Exceptions/` | `App\Bridge\Exceptions` |
| `app/Console/Commands/Bridge/` | `App\Console\Commands\Bridge` |
| `app/Http/Controllers/Webhook/` | `App\Http\Controllers\Webhook` |
| `app/Http/Middleware/` | `App\Http\Middleware` |
| `app/Models/` | `App\Models` |
| `tests/` | `Tests` |

### Value objects / DTOs

Data shapes in `app/Bridge/Dispatch/` and `app/Bridge/Adapters/` are `final` classes with constructor property promotion and `readonly` on every field. There is no freeze/thaw machinery — plain PHP arrays carry payload; hashability is a non-issue in PHP (DL-001 obsoletes the Python frozen-dataclass pattern entirely).

```php
final class EventDto
{
    public function __construct(
        public readonly string $deliveryId,
        public readonly string $scopeId,
        public readonly string $eventType,
        public readonly ?string $actorId,
    ) {}
}
```

Named constructor `make()` (with sensible defaults) is the pattern for shapes that have optional fields with non-trivial defaults (`ReactionTarget::make`). The `__construct` accepting all fields stays the canonical form.

`toArray()` on a DTO produces the canonical wire shape (inbox JSONL / channel). See `Intent::toArray()`.

### Interfaces (contracts)

Contracts live in `App\Bridge\Contracts`. Each is an `interface`, not an abstract class. Operators implement `Classifier` and `Handler` for custom behaviour; built-in impls live in `App\Bridge\Classifiers/` and `App\Bridge\Handlers/`.

### Exceptions

All bridge-specific exceptions extend `RuntimeException` and live in `App\Bridge\Exceptions\*Exception`. They are `final` classes with no added methods — the message is the contract. The class name identifies the failure category; callers that need to distinguish exception types `catch` the specific class.

### Artisan commands

Command class: `<Verb>Command` in `app/Console/Commands/Bridge/`. Not `final` (Artisan's command resolution doesn't require it, and Mockery-based testing of command internals benefits from non-final).

Signature: `bridge:<verb>` in `$signature`. Options use `{--flag=default : description}` inline documentation.

Return `self::SUCCESS` or `self::FAILURE` (never bare integers) from `handle()`.

### Eloquent models

Models live in `app/Models/`. They follow standard Laravel conventions: explicit `$fillable` (both `WebhookEvent` and `AgentDispatch` use it), typed `$casts` for JSON/timestamp columns, and no business logic — the models are plain Eloquent. The at-least-once write primitive is `DispatchService::dedupCreate()` (a private method on the service, NOT on the models): `$class::create()` → catch `UniqueConstraintViolationException` → refetch — used for both `webhook_events` and `agent_dispatches`. NOT `firstOrCreate` (that is SELECT-then-INSERT and races).

### Migrations

Filename: `YYYY_MM_DD_NNNNNN_<snake_case_description>.php`. Schema changes that add columns get a new migration; never edit a shipped migration. The `down()` method drops what `up()` created.

### Validators

`App\Bridge\Validation\*` classes are `final` with a single `public static function matches(string $value): bool` (or `isValid`). No instantiation — pure static predicates. Used by config loaders and provisioning to reject malformed inputs at the system boundary.

## Code style and static analysis

**Pint** (Laravel preset) enforces formatting. Run `vendor/bin/pint --test` before opening a PR; `vendor/bin/pint` to apply. The `pint.json` excludes legacy directories (`bin`, `lib`, `receiver`, `examples`).

**PHPStan** (via larastan) at level 7, scoped to `app/Bridge/**` via `phpstan-laravel.neon`. Run `vendor/bin/phpstan analyse -c phpstan-laravel.neon`. PHPDoc `@param` / `@return` annotations are required wherever PHPStan can't infer the generic type (array shapes, list vs. array, generics on Eloquent).

No `declare(strict_types=1)` in this codebase — Laravel's own files don't use it uniformly; enforcing it selectively would create a patchwork. PHPStan at level 7 catches the type errors that matter.

## Comments

**Default: no comments.** Identifiers should do the explaining.

Write a comment when the *why* is non-obvious — hidden constraints, subtle invariants, workarounds for specific bugs, behavior that would surprise a reader. Cite the source: a `CLAUDE_DECISIONS.md` DL-NNN entry, a `CLAUDE_GOTCHAS.md` section, or a specific requirement number.

**Anti-patterns:**
- `// Increment the counter` next to `$count++` (restates code)
- `// Used by bridge:replay to re-run dispatch` (rots when callers change)
- `// Fixed bug from PR #42` (belongs in the PR body, not the code)

Worked example of a good comment, from `DispatchService`:
```php
// Refuse to write to a crosstalk-mismatched DB. A misconfig here is a
// 5xx (fail-closed) — kanban-board holds the event and redelivers once
// the operator fixes the install, rather than letting a -dev install
// write into the prod DB (or vice versa).
```
The why (fail-closed, redelivery contract, crosstalk risk) is impossible to derive from the code; the DL citation is load-bearing.

**A `Class::member` citation is a claim, and CI reads it — in the `{@see …}` spelling as well as the backticked one.** `bin/check-doc-refs.php` resolves the MEMBER, not just the class, across every source it scans — `app/`, `tests/`, `docs/`, `bin/` and the root `*.md`, the append-only decision log and CHANGELOG included, where a frozen entry discharges a citation the tree no longer answers for by carrying a removed-marker in an annotation appended beside the original sentence (an alternative the entry says it *rejected* needs nothing). **An annotation discharges only the citations written in the same SENTENCE as its marker**, so an appended one has to repeat the citation it is discharging — as a citation, in either spelling — because "removed in v0.60" at the foot of a long entry names none of them, and a marker that reached the whole line would switch the gate off for every live construct that entry goes on to cite (card#7127). **The harvest was backtick-only until card#7330**, which is why the docblock form is called out here: a phantom cited as `{@see …}` was not reported, not counted, and not distinguishable at the exit code from a tree that had none. What the form does NOT change is the token set — a payload is read exactly when its backticked twin would be, so a pseudo-class (`self::`) or a class-less member name inside the tag is **unexamined**, which is not protection. **Since card#7473 both are also COUNTED** — until then they were absent from the examined half AND from the remainder, so the run's own accounting read as complete over a population defined by what it could answer, and qualifying one such citation MOVED the total instead of moving it between buckets. **card#7496 counted the third such payload, a `{@see SomeClass}` naming a class and NO member** — invisible to the member leg for want of a `::` and out of the FQCN leg's six-doc scope for being written in an `app/` docblock, so it was unread by both legs and in neither's accounting. It is still unexamined; resolving it was measured before it was declined, and on this tree it would have convicted nothing but false positives. Cite the construct you mean; the script's own docblock owns the token shapes and the bounds — including the one those three buckets do not reach, a class-less member whose name is ALL-CAPS — and `--census` prints what a run examined against what it declined to answer.

Class-level docblocks are used for shapes where the field semantics aren't obvious from names alone (`Actor`, `ReactionTarget`, `EventDto`). Method-level docblocks are used when the behaviour has a non-obvious invariant not captured by the PHPDoc type annotations. Trivially-named one-liners don't get docblocks.

## Logging

Bridge code uses Laravel's `Log::warning(...)` / `Log::error(...)` facade for diagnostics. Library code (services, registries) never configures the log channel — that's Laravel's job via `.env LOG_CHANNEL`. Log messages follow the `bridge <subsystem>: <what happened>` prefix pattern (`bridge dispatch: classifier failed`, `bridge dispatch: handler failed`) so operators can grep by subsystem.

## Git / PR conventions

### Branch naming

`feature/<short-slug>` for new work. `fix/<short-slug>` for bugfix-only PRs. `chore/<short-slug>` for process/tooling work. `refactor/<short-slug>` for behavior-preserving restructures.

**Base branch:** owned by [`CLAUDE.md`](CLAUDE.md) standing rule 4 (the two-branch model — deliberately not restated here).

### Commit messages

Subject line: `<type>(<scope>): <short description>` (matches Conventional Commits).
- `feat(layer-N)`: new feature in a specific layer
- `fix(layer-N)`: bug fix
- `chore(release)`: version bump
- `docs`: documentation-only
- `test`: test-only
- `refactor`: code restructure without behavior change

Body: paragraphs describing what + why (NOT how — that's in the diff). Include test count delta. Include `Co-Authored-By:` trailer when generated.

### PR descriptions

- One-line summary at top.
- "What's in this PR" list of concrete additions.
- "Senior-dev review loop" section listing pass-1 findings + fixes + pass-2 verdict. **Required.** See [`feedback-review-agent-loop-before-pr`](../.claude/projects/-home-kanban/memory/feedback-review-agent-loop-before-pr.md).
- Test count delta.
- Subsequent-PRs note if part of a multi-PR feature.

### Workflow

The dev-PR gate model — branching model, who opens and merges what, and what CI must show before a self-merge — is owned by [`CLAUDE.md`](CLAUDE.md) standing rules 4–5; the release steps are owned by [`VERSIONING.md`](VERSIONING.md) § Release flow. The merge mechanic itself is in [`CLAUDE_AGENTBOARD.md`](CLAUDE_AGENTBOARD.md) § Your work loop → **Merge authority**. This file deliberately does not restate any of it: a restated copy here drifted into contradicting the owners on the ask-before-open checkpoint (retired), the named-workflow list (card#5575), and hand-tagging (card#5913).

**Security-critical surfaces still pause.** The surfaces are `VerifyHmacSignature.php` / adapters / HMAC paths / secret-path resolution / DB schema; the trigger is **changes to what these surfaces accept, reject, or persist** — not the fact that a change touches one of them (a formatting-only edit carries no gate). Such a change gets explicit human approval before being implemented — see [`CLAUDE_AGENTBOARD.md`](CLAUDE_AGENTBOARD.md) § Ask-first gates and the user-level always-ask gate. The gate is on the change, not the dev merge (`CLAUDE.md` rule 5: no per-merge dev ask).

## Test conventions

See [`CLAUDE_TESTING.md`](CLAUDE_TESTING.md) for the full testing guide. Naming-only here:

- Test files mirror source: `app/Bridge/Dispatch/DispatchService.php` → `tests/Feature/Dispatch/DispatchServiceTest.php`; unit-level shapes in `tests/Unit/`.
- Test method names: `test_<scenario>_<expected_outcome>`. Example: `test_classify_exception_records_error_and_acks_200`, `test_scope_mismatch_returns_401`.
- One assertion per test where practical; multiple when verifying related properties of the same behaviour.
- Fixture names: short, descriptive (`$event`, `$dispatch`, `$agent`).

## Security / secrets conventions

- **Never commit secrets.** Webhook signing keys, DB passwords, API tokens stay in `~/.config/agent-webhook-bridge/<agent>.yml` (chmod 600) or the Laravel `.env` (`DB_PASSWORD`). Both are gitignored.
- **Templates ship; populated files don't.** `.env.example` and `examples/sample-config/agent.yml.example` go in the repo with placeholders.
- **HMAC compares use `hash_equals`** — never `==`. Constant-time compare on signatures. See `VerifyHmacSignature.php`.
- **HTTPS on the secret-bearing endpoints.** A provider API base URL carries the writeback bearer and, at provision time, the freshly-minted webhook HMAC secret, so it is validated with `UrlValidator::secureHttpUrl` — cleartext `http` is refused unless the host is loopback. The receiver base URL goes through `UrlValidator::httpUrl`, which enforces the URL shape and an http(s) scheme but **no** https floor. Those two endpoints are `UrlValidator`'s only callers — a URL-shaped value elsewhere is validated by whoever owns it: `channel.url` is shape-checked at its parse site in `AgentConfig` (non-empty, no whitespace; the loopback/SSRF gate is the `channel_push` handler's), and `alert_channel.url` defers to the sender's own `LocalhostUrl::assertValid`.
- **SHA-pin every third-party GitHub Action.** Format: `uses: <owner>/<repo>@<full-40-char-SHA>  # vX.Y.Z`. The `# vX.Y.Z` comment is load-bearing — dependabot parses it. Reject mutable tag references (`@v4`) at PR review.

## Scope discipline

- Don't add features, refactor, or introduce abstractions beyond what the task requires. A bug fix doesn't need surrounding cleanup.
- Don't design for hypothetical future requirements. Three similar lines is better than a premature abstraction.
- Don't add error handling, fallbacks, or validation for scenarios that can't happen. Trust internal code and framework guarantees; only validate at system boundaries (the receiver IS a system boundary; everything inside is internal).
- No half-finished implementations.

## When in doubt

Match the existing code's style. If you're adding a new class parallel to an existing one (e.g. a third provider adapter), copy the closest existing one and modify. Don't invent new conventions when an existing one fits.

If you find yourself diverging from these conventions because the existing approach is genuinely worse, document the divergence in `CLAUDE_DECISIONS.md` with a DL-NNN entry. Conventions are mutable when the reasoning is sound — but the change goes in writing first.
