# Periodic jobs

> **⛔ Read this section before you add a job. It is the point of the document.**
>
> **A periodic job is the LAST RESORT in this design.** The bridge's first answer to *"this
> needs to happen regularly"* is the **after-response event gate**, and that is not a style
> preference — it is DL-199's symmetry argument: the thing that CREATES the work is usually
> the thing that can EVALUATE the gate. `webhook_events` grows only when a webhook arrives,
> and the retention gate is evaluated when a webhook arrives, so a silent install accrues
> nothing and needs no prune. No clock required, no crontab line, nothing to die quietly.
>
> Before reaching for a job, answer these in order and stop at the first **yes**:
>
> 1. **Is the work created by an event this bridge already receives?** Then gate it on that
>    arrival, after the response, bounded — the shape `App\Bridge\Retention\RetentionGate`
>    and `App\Bridge\Standup\StandupGate` already use. No job.
> 2. **Can the consumer ask for it when it needs it?** A seat's session-start hook, a
>    `bridge:*` command in a runbook, a check leg at preflight. A pull at the moment of use
>    beats a push on a timer nobody is watching. No job.
> 3. **Can the work be done at the moment it becomes necessary,** by whatever made it
>    necessary? Cleaning up after yourself is not periodic work. No job.
> 4. **Only if none of those hold:** the work has no creating event on this install and no
>    consumer that can ask — a watch on something the bridge does not receive, or work that
>    must happen on an install receiving no traffic at all. That is a job, and the sentence
>    you just wrote answering *"why not 1, 2 or 3?"* is the `justification` the registry
>    requires at insert.
>
> The registry **refuses an instance with no justification**. That is not an approval gate —
> nobody is consulted, insertion stays programmatic and happens at runtime — it is a
> required argument, and it costs exactly one sentence. It exists because the alternative
> is a periodic population that nobody can audit for *why*, only for *what*.

---

## What this is

One **external tick** drives a registry of jobs that are **data**, not frozen config. Any
code path may insert or remove a job instance **at runtime**; the crontab line never
changes. `bridge:jobs` enumerates the entire periodic population of the install on demand —
which no sweep across N crontabs on N accounts could give you.

| | |
|---|---|
| **Registry** | `scheduled_jobs` — one row per job INSTANCE |
| **Runtime API** | `App\Bridge\Scheduling\JobRegistry` (`insert`, `remove`, `setEnabled`, `all`) |
| **Instance shape** | `{name, handler, interval, owner, docs-ref, justification, enabled, payload}` |
| **Handler contract** | `App\Bridge\Scheduling\JobHandler` — `name()`, `capability()`, `run()` |
| **Scheduler** | `App\Bridge\Scheduling\JobScheduler` — one bounded, non-blocking pass |
| **Ingress A (default)** | `App\Bridge\Scheduling\JobSchedulerGate` — after-response, off the inbound webhook |
| **Ingress B (opt-in)** | `php artisan bridge:tick` — one crontab line |
| **Enumeration / edit** | `php artisan bridge:jobs [--json] [--assert-tick]` |
| **Preflight leg** | `bridge:check` → `jobs.posture` |

## Two ingresses, and why both exist

The registry runs from **either** ingress, and they are additive:

- **The event gate** is DL-199's shape, and it is the default on every install. It needs no
  crontab line, no daemon and no operator action. **An install that adopts nothing from this
  document keeps behaving exactly as it does today.**
- **The tick** is one crontab line, and it is **opt-in per install**. It buys exactly one
  thing the event gate cannot: periodic work on an install receiving **no webhooks**. DL-306
  wrote that dead end down against itself — *"the pass fires on the first inbound webhook
  AFTER the interval lapses, so an install receiving nothing pushes nothing"* — and no
  arrival-gated mechanism can close it, because the gate's clock **is** the traffic.

So they cover different blind spots: **the gate covers the busy install, the tick covers the
silent one.** Neither replaces the other, and shipping only the tick would have made a
crontab line a hard dependency of running the bridge.

Both ingresses share ONE pass, with DL-199's four properties intact — **after-response**
(job execution is never on a client-visible path), **bounded** (`jobs.max_per_pass`,
oldest-due first), **non-blocking `Cache::lock`** (a blocking lock is forbidden: it would
queue concurrent receives behind a job, the DL-001 latency regression), and **never throws
past the pass**.

## Adopting the tick

It is one line, under **the seat-owner account, never root**:

```cron
0,10,20,30,40,50 * * * * cd /path/to/bridge && php artisan bridge:tick >> /path/to/bridge/storage/logs/tick.log 2>&1
```

Then **declare the interval you used**, in seconds, so a dead crontab line goes loud:

```dotenv
BRIDGE_JOBS_TICK_EXPECTED_EVERY=600
```

⚠ On an install running `php artisan config:cache`, a `.env` edit is **inert** until the
cache is rebuilt. See `CLAUDE_DEPLOYMENT.md`.

That is the whole adoption. You never add a second crontab line: everything else is a row in
the registry.

**What the crontab line's exit code means.** `bridge:tick` exits **0** whenever the pass ran
— including when jobs inside it FAILED or were REFUSED, which are recorded on their own rows
and reported by `bridge:jobs` / `bridge:check` — and also on an ORDINARY skip: another pass
holds the lock, the shared minimum interval has not elapsed, or the registry is switched off.
It exits **non-zero only when the pass could not run because something is broken**: an
unreachable database, an unusable cache backend, or a `BRIDGE_JOBS_*` cadence this install
cannot act on. A line that reddened on ordinary skips would mail its operator on most runs of
a busy install, and the mail that would have carried a real fault gets filtered within a week.
⚑ The tick is **stamped before the pass either way**, so a fault does not also read as a dead
clock — those are two different alarms.

⛔ **A DEAD CACHE BACKEND IS A REPORTED FAULT, NOT A STACK TRACE**, and it only became one
when the pass's fault-recording moved behind `App\Bridge\Support\FaultMarker`. The catch arm
recorded the fault by WRITING TO THE CACHE, so when the cache was the fault the arm re-raised
its own exception: `bridge:tick` died at the tick stamp with a trace and exit 1 *by accident*
— no summary line, no log line, no marker — and the event ingress ended every delivery with
an unhandled fatal in the FPM worker. The fault is now **logged first** and the marker written
second and guarded, so what an operator sees on a dead cache store is the ordinary contract
above: one summary line, one log line, exit 1. ⚠ The last-pass-failure marker (the one
`bridge:check`'s `jobs.posture` leg reports) is the one thing a dead cache CANNOT leave
behind — the marker lives in the store that failed. Read the log, not the marker, when the store is the
suspect; and a tick whose stamp could not be written reads as `unmeasured`, never as fresh.

## Death is the alarm

The tick becomes the single point of failure for every periodic job on an install that
adopted it, and the failure mode is **silence**. `bridge:prune` shipped at DL-012 and was
scheduled nowhere — three installs, ~45 days, zero prunes, and nothing anywhere said so. A
registry with better ergonomics and the same blind spot would be that defect with a nicer
listing.

So the bridge records the last tick it received, and the freshness verdict is measured
against **the operator's own declaration**, never a fleet-wide constant — only this install
knows what its crontab line says.

| state | means |
|---|---|
| `unmeasured` | **No tick has ever been recorded.** The ordinary reading on an install that has not adopted the tick — and the honest reading when one that HAS adopted it has never been seen. It means *nothing measured*, **never** *dead*. |
| `undeclared` | A tick was recorded, but this install declared no expected interval. An age is known; **no verdict is claimed**, because inventing a default constant to produce one is exactly what this design refuses. |
| `fresh` | A horizon was declared and the last tick is inside it (one extra interval + 60s of slack, for cron jitter). |
| `stale` | A horizon was declared and the last tick **blew it**. Evidence on any install, with no tuning, because the install set the number itself. |

Assert it from a session-start hook:

```bash
php artisan bridge:jobs --assert-tick     # exit 1 only when a DECLARED tick is not fresh
php artisan bridge:jobs --json            # the same facts, with the inputs beside the verdict
```

⛔ **Only a declared horizon can fail an assertion.** An install that never adopted the tick
is not failing by not ticking, so this can never be adopted by accident.

## Governance: handlers are the surface, instances are free

- **A job entry may only reference a handler that EXISTS in bridge code.** What a job *can
  do* is therefore fixed at **code-review time**. A row naming a handler this build does not
  have is a **LOUD refusal** — at insert, and again at run — never a silent skip: the
  instance records `refused` with the reason, the scheduler logs at error, and
  `bridge:check` fails on it.
- **Board/state-mutating handlers require operator approval to exist at all.** That is
  encoded structurally, not as a comment: a handler declares
  `JobCapability::MutatesState`, and it is **inert** until this install names it in
  `BRIDGE_JOBS_ARMED_MUTATORS`. Read-and-alert handlers (staleness checks, wakes, watches,
  cleanups) declare `JobCapability::ReadAndAlert` and exist under normal code review.
- **Instances are free.** Inserting or removing an instance of an already-reviewed handler
  is ungated, programmatic and runtime. The only thing an inserter owes is the
  `justification` sentence — a required argument, not an approval.

⚠ What the capability declaration does NOT establish: it records what the author *claims*.
A handler that writes to a board while declaring `ReadAndAlert` is mis-declared, and nothing
detects that. Its job is to make the claim reviewable and to make arming an explicit
operator act — not to sandbox the handler.

## Inserting a job from code

```php
use App\Bridge\Scheduling\JobRegistry;
use App\Bridge\Scheduling\JobSpec;

app(JobRegistry::class)->insert(new JobSpec(
    name: 'coord-thread-watch',
    handler: 'my_watch_handler',
    intervalS: 900,
    owner: 'pm-agent',
    docsRef: 'docs/periodic-jobs.md#coord-thread-watch',
    justification: 'the watched threads live in a repo this install receives no webhooks from, so there is no arrival to gate on',
));
```

`insert` is an **upsert by name** — a caller that re-declares its job on every boot
converges on one row. It **throws** `JobSpecException` rather than returning false: a
program that ignores a boolean ends up believing it scheduled something it did not.
⚠ **Re-declaring with a NEW `interval_s` does not re-date the pending run**: `next_due_at`
was written by the last pass from the OLD interval and is left alone, so the new cadence
starts from the next time the job actually runs — shortening an interval does not pull the
pending run forward, and lengthening one does not push it back.

Or by hand:

```bash
php artisan bridge:jobs add coord-thread-watch \
  --handler=my_watch_handler --interval=900 --owner=pm-agent \
  --docs-ref='docs/periodic-jobs.md#coord-thread-watch' \
  --justification='no arrival to gate on: the watched repo does not deliver here'

php artisan bridge:jobs                 # the whole periodic population, justifications included
php artisan bridge:jobs disable coord-thread-watch
php artisan bridge:jobs remove coord-thread-watch
php artisan bridge:jobs run             # one pass now
```

## Writing a handler

```php
final class MyWatchJob implements JobHandler
{
    public function name(): string { return 'my_watch_handler'; }

    public function capability(): JobCapability { return JobCapability::ReadAndAlert; }

    public function run(JobContext $ctx): JobOutcome
    {
        // BOUNDED. On the event ingress this runs inside an FPM worker after the
        // response; an unbounded pass holds a worker. Do a chunk, return, continue
        // next pass — the same rule DL-199's retention batch follows.
        return JobOutcome::ok('checked 12 threads, 0 stale');
    }
}
```

Register it against the container singleton, from a service provider **both** the FPM worker
and the CLI process load (see `docs/customization.md`) — a handler wired anywhere else
exists on one ingress and is a loud refusal on the other:

```php
$this->app->afterResolving(
    App\Bridge\Scheduling\JobHandlerRegistry::class,
    fn ($registry) => $registry->register(new MyWatchJob),
);
```

Failure is a **throw**; the scheduler catches per job, records the exception on the
instance's own row (`last_status`, `last_error`, `consecutive_failures`), and neither the
webhook response nor the tick's exit code is affected. `bridge:jobs` prints it and
`bridge:check` warns at three consecutive failures.

## Shipped handlers

| handler | capability | what it does |
|---|---|---|
| `standup_digest` | `read_and_alert` | Asks `App\Bridge\Standup\StandupGate::runPass()` — the PM digest (DL-306), on a wall clock instead of a delivery cadence. Both ingresses share the digest's own interval marker, so the digest is still pushed at most once per `BRIDGE_STANDUP_INTERVAL` however many things asked. The instance's `interval_s` is how often the scheduler **asks**; `BRIDGE_STANDUP_INTERVAL` is how often it **pushes**. |

## Staleness of things that are NOT the tick

A watchdog job that judges some other record's freshness — a seat's state, a queue, a
mirror — inherits a rule this repository has already ruled on, and it is binding on any such
handler added here:

- **The threshold lives on the RECORD, not on the watchdog.** Age alone cannot separate
  *wedged* from *legitimately busy*: a seat mid-way through a long build is `working` and
  healthy at 40 minutes; a wedged seat is `working` and dead at 40 seconds. A single
  fleet-wide constant makes one of those two wrong on every install, and the tuning pressure
  lands on whoever gets paged. **A record's writer declares its own expected-next-boundary,
  and staleness is measured against that declaration.** A seat that blows its OWN declared
  horizon is evidence on any install, with no tuning.
- **No declared horizon ⇒ a conservative default AND a weaker verdict.** The output is
  `suspect`, never `wedged`, and the action must be a **NUDGE** — never anything
  destructive. A watchdog that acts destructively on an inferred state is worse than no
  watchdog.
- **An ABSENT record is UNMEASURED, not stale.** It is the third state and must be reported
  as such. Reading absence as death is how a watchdog fires on a seat that simply has not
  adopted the schema yet.
- **A fixed `interval_s` is meaningful only on records the scheduler itself emits** — it
  follows from the above: a cadence claim is meaningful exactly where a fixed cadence exists.

The tick's own freshness (above) is that rule applied to the one record this card ships: the
horizon is the operator's declaration, absence is `unmeasured`, and an undeclared install
gets an age and no verdict.

## Config

| key | default | what it does |
|---|---|---|
| `BRIDGE_JOBS_ENABLED` | `true` | The registry as a whole. With no rows it costs one indexed query per `min_pass_interval` on delivery. `false` registers no callback at all. |
| `BRIDGE_JOBS_MIN_PASS_INTERVAL` | `60` | Floor between passes, **shared by both ingresses** (the event gate is evaluated on every delivery). A 5/10/15-minute tick is never affected by it. ⚠ A value that is not a positive integer is **REFUSED, never clamped**: no pass runs on either ingress, `bridge:check`'s `jobs.posture` leg **fails**, and `bridge:tick` exits non-zero. (`sixty` reads as `0`; clamping it to 1 would have turned an intended 60-second floor into a pass per second, silently.) |
| `BRIDGE_JOBS_MAX_PER_PASS` | `3` | The bound. A backlog drains across passes; a pass is never unbounded. ⚠ Refused the same way outside `1…1000`. |
| `BRIDGE_JOBS_ARMED_MUTATORS` | *(empty)* | Comma-separated handler names this install has armed. The only ask-the-operator gate in the subsystem. |
| `BRIDGE_JOBS_TICK_EXPECTED_EVERY` | *(unset)* | The tick adoption knob **and** the death-is-the-alarm horizon, in seconds. Unset ⇒ the tick was not adopted and its absence is never reported as a fault. ⚠ A value that is not a positive integer arms **nothing** and reads as unadopted — `bridge:check` warns on one, because that is the only place an operator who set it wrongly finds out. |
