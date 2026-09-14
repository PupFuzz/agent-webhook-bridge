# Delivery-gap fixture, 2026-08 (per-scope daily delivery counts)

The per-scope daily counts the affected seats published while diagnosing the 2026-08-22/23 incident, in which
`PupFuzz/agent-roundtable` went deaf on each of them for weeks while their receivers kept recording other scopes.
`ScopeDeliveryHistoryFixtureTest` replays `bridge:check`'s `github.delivery_history` derivation over it (DL-382).

## ⛔ What this is NOT

**It is not the capture aimla-pm committed** (`AIMLA-org/aimla-coordination`, `share/bridge-delivery-gap-2026-08/`
at `a1dacaf0`). That repository answered HTTP 404 to the token this fixture was built with, so its CSV was never
read. Every figure here is instead copied from what those seats **published on `PupFuzz/agent-roundtable#434`**,
and nothing was filled in: a day neither seat published a count for is ABSENT, never a zero.

| `series` | `instrument` | source on rt#434 |
| --- | --- | --- |
| `aimla-pm-roundtable` | `webhook_events` (`scope_id`, `received_at`), zero-filled by the publisher | comment 5650050366, § 2 |
| `aimla-pm-control` | `webhook_events`, same export, the `AIMLA-org/aimla-coordination` scope | comment 5650050366, § 2 and § 3(a) |
| `aimla-pm-roundtable` | `inbox_intents` — staged intents, bucketed per day | comment 5600286413 |
| `kanban-solo-roundtable` | `inbox_intents` | comment 5600258649 |

## Bounds that ride with it

- **Every series is contiguous** from its first published day to its last, and the test refuses a series that is
  not. That is what keeps an unpublished day out: an absent day in the MIDDLE of a series would read as a day with
  no deliveries.
- **`aimla-pm-control` places its one in-gap zero by inference.** The publisher listed the delivering days
  of 2026-08-24 → 2026-09-08 in order and separately named 2026-08-30 as the zero; the row order here follows from
  those two statements. The control's other zeros (2026-08-15, 2026-09-11) fall outside the published run and are
  not here.
- **`inbox_intents` counts STAGED INTENTS, not deliveries.** A delivery that stages nothing for the seat is not
  counted, so those series are sparser than the delivery record they stand in for. They are kept because they
  begin days before the gap, where the `webhook_events` slice published for the roundtable scope begins the day
  before it: `kanban-solo-roundtable`'s longer record holds the single-delivery quiet day the test must reach, and
  a record spanning the outage once the scope resumes, which the resume test reads.
- **No named window is judged by a DERIVED threshold.** No series' record spans the two weekly cycles a derivation
  needs when its window opens, so inside every named window the replay reaches only `underived_past_floor`, never
  `past_threshold`. The derived term is covered by the unit cases in `ScopeDeliveryHistoryTest` and by the resume
  test; checking the derived path against incident data waits on aimla-pm's full capture.
- **Day buckets on a corrected-timestamp instance** (comment 5650050366 § 3(b)): the aimla-pm buckets were taken after
  migration `2026_09_05_000001_correct_php_written_timestamps_to_utc`, so a ±1-day boundary effect on the first or
  last day of a run cannot be excluded. The test keys on each run as the fixture records it, not on calendar dates.
- **A daily count has no intra-day shape.** The test places a day's `count` deliveries evenly across that day. That
  placement is the TEST's, not a measurement, and it cannot express what the source could not either — e.g. that
  2026-08-23's partial count came from a hook dying mid-day.
