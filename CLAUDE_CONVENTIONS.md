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

Class-level docblocks are used for shapes where the field semantics aren't obvious from names alone (`Actor`, `ReactionTarget`, `EventDto`). Method-level docblocks are used when the behaviour has a non-obvious invariant not captured by the PHPDoc type annotations. Trivially-named one-liners don't get docblocks.

## Logging

Bridge code uses Laravel's `Log::warning(...)` / `Log::error(...)` facade for diagnostics. Library code (services, registries) never configures the log channel — that's Laravel's job via `.env LOG_CHANNEL`. Log messages follow the `bridge <subsystem>: <what happened>` prefix pattern (`bridge dispatch: classifier failed`, `bridge dispatch: handler failed`) so operators can grep by subsystem.

## Git / PR conventions

### Branch naming

`feature/<short-slug>` for new work. `fix/<short-slug>` for bugfix-only PRs. `chore/<short-slug>` for process/tooling work. `refactor/<short-slug>` for behavior-preserving restructures.

**Base branch: always `dev`.** The `main` branch is release-only and merged to by the user. Never branch directly from `main` for new work, and never PR a feature branch directly to `main`.

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

Two-branch (`main` + `dev`). Adopts the kanban-board process.

**Feature flow:**

1. `git checkout -b <feature-branch>` off `dev` (never off `main`).
2. Commit on the feature branch.
3. Run the review-agent loop until CLEAN.
4. **ASK user before `gh pr create`.** Wait for explicit go-ahead.
5. Open PR targeting `dev`.
6. Wait for ALL CI workflows (Laravel Tests + Security + any future) to complete + pass.
7. **Auto-merge on green** — Claude runs `gh pr merge --squash --delete-branch` once all checks are green. No second ask. Per [`feedback-git-workflow`](../.claude/projects/-home-kanban/memory/feedback-git-workflow.md): opening creates a visible artifact worth a checkpoint; merging validated work doesn't.

**Releases.** When `dev` accumulates changes worth tagging:

1. Claude bumps `VERSION` (per [`VERSIONING.md`](VERSIONING.md)) + updates `docs/CHANGELOG.md` on a release-prep feature branch off `dev`.
2. Ask before opening; auto-merge to `dev` on green.
3. After the release-prep PR is on `dev`, ask before opening the release PR `dev` → `main`.
4. Wait for ALL CI workflows on the release PR.
5. **Hand off to user to merge** — Claude never runs `gh pr merge` against a `main`-targeted PR regardless of CI state.
6. After the user merges to `main` and confirms the merge, that confirmation is the standing authorization for both the tag AND the back-merge sync PR. Claude:
   - Tags the new main commit with `v<VERSION>`.
   - Opens the back-merge sync PR (`sync/main-to-dev-post-v<version>`) targeting `dev` **autonomously — no separate ask**.
   - Auto-merges the sync PR on green (it targets `dev`).

**Security-critical surfaces still pause.** Even on `dev`, PRs touching `VerifyHmacSignature.php` / adapters / HMAC paths / secret-path resolution / DB schema get explicit human approval before merging — on top of the ask-before-open baseline.

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
- **HTTPS-only.** Webhook receiver URLs are `https://`; validated in `AgentConfig::validateUrl`.
- **SHA-pin every third-party GitHub Action.** Format: `uses: <owner>/<repo>@<full-40-char-SHA>  # vX.Y.Z`. The `# vX.Y.Z` comment is load-bearing — dependabot parses it. Reject mutable tag references (`@v4`) at PR review.

## Scope discipline

- Don't add features, refactor, or introduce abstractions beyond what the task requires. A bug fix doesn't need surrounding cleanup.
- Don't design for hypothetical future requirements. Three similar lines is better than a premature abstraction.
- Don't add error handling, fallbacks, or validation for scenarios that can't happen. Trust internal code and framework guarantees; only validate at system boundaries (the receiver IS a system boundary; everything inside is internal).
- No half-finished implementations.

## When in doubt

Match the existing code's style. If you're adding a new class parallel to an existing one (e.g. a third provider adapter), copy the closest existing one and modify. Don't invent new conventions when an existing one fits.

If you find yourself diverging from these conventions because the existing approach is genuinely worse, document the divergence in `CLAUDE_DECISIONS.md` with a DL-NNN entry. Conventions are mutable when the reasoning is sound — but the change goes in writing first.
