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

## Test plan

<!-- Bullets covering what was tested. Acceptable items:
     - `vendor/bin/phpunit` (full suite green; cite count)
     - `vendor/bin/pint --test` clean
     - `vendor/bin/phpstan analyse -c phpstan-laravel.neon` clean
     - Manual smoke-test against a live install (cite which install)
     - Senior-dev review-agent loop CLEAN
     - CI on this PR (green required for auto-merge per feedback-git-workflow) -->

🤖 Generated with [Claude Code](https://claude.com/claude-code)
