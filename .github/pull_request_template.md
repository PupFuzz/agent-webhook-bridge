## Summary

<!-- 1-3 bullets: what changes, why. The "why" should reference the
     surfacing context — a DL-NNN, audit pass, or incident. -->

## Scope

- **Closes / references:** <!-- DL-NNN, issue, or "none" -->
- **Migration impact:** applies / n/a
  <!-- "applies" if this PR ships a new `database/migrations/*` file OR changes
       `config/bridge.php` / the per-agent YAML schema. "n/a" otherwise. If
       applies → update `CLAUDE_DEPLOYMENT.md` in the same PR (doc-sync per
       standing rule 2). -->
- **Operator action required after merge:** yes / no
  <!-- "yes" if operators must run something beyond the standard update
       (`git pull → composer install → php artisan migrate → php artisan bridge:check`).
       Spell out what. -->

**Built:** dispatched (coder ×N / mechanic ×M) | inline (trivial-tier: <one-line reason>) | inline (dispatch-prohibited: <one-line reason>) | in-session (docs/coordination)
<!-- REQUIRED on every PR body — keep exactly ONE value, delete the others, and leave the line
     UNBULLETED at column 0 with the value UNQUOTED: the plane-1 audit is line-anchored and
     matches the value against the canonical set, so a leading `- ` reads as ABSENT and a
     wrapping backtick reads as out-of-set. The value set, and when each value is legitimate,
     is owned by the coord plugin's `docs/built-line.md` § "The `Built:` line"; read it there —
     deliberately not restated here, because a second copy drifts. The values above are the four
     a seat declares for its own work; the fifth, `unattestable — <reason>`, is restricted to two
     conditions that doc owns and is not offered here. -->

<!-- The card this work is coordinated on. Same line-anchored, unquoted, unbulleted shape as
     `Built:` above; the value is `card#NNNN`. Left EMPTY on purpose — a placeholder after the
     colon would be read as the answer and PASS an unfilled row. -->
**Coordinated in:**

## Test plan

<!-- Bullets covering what was tested. Acceptable items:
     - `vendor/bin/phpunit` (full suite green; cite count)
     - `vendor/bin/pint --test` clean
     - `vendor/bin/phpstan analyse -c phpstan-laravel.neon` clean
     - Manual smoke-test against a live install (cite which install)
     - Senior-dev review-agent loop CLEAN
     - CI on this PR (green required for auto-merge per feedback-git-workflow) -->

🤖 Generated with [Claude Code](https://claude.com/claude-code)
