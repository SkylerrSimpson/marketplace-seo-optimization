# Contributing to DOWScripts

See `CLAUDE.md` for the non-negotiable rules (wrapper-not-rewrite, safety model, TDD).
This file is the how-to.

## Local setup

```
cd dowscripts
php8.2 /usr/local/bin/composer install
cp .env.example .env        # already done if you're reading this post-scaffold
php8.2 artisan key:generate
touch database/database.sqlite
php8.2 artisan migrate
php8.2 artisan serve
```

This project targets PHP 8.2+. The parent repo's own scripts run on the system's
default PHP (currently 8.1), so always invoke Composer/Artisan here explicitly via
`php8.2`, not the bare `composer`/`php artisan` — the bare commands will resolve to the
wrong PHP version and Composer will refuse to install Laravel's dependencies.

## Adding a new wrapped script

This is the main way this codebase grows. It should never require a new class or a new
controller — only:

1. **Add a config entry** in `config/scripts/{marketplace}.php` describing the script:
   slug, title, description, usage notes, `type` (`Read`/`Write`), the CLI path relative
   to this file, and its list of `ParamDefinition`s (name, CLI flag, type, required,
   default, options for enums like `account: dows|ige`, help text).
2. **Write tests first** (see below) asserting `ScriptRegistry` picks up the new entry
   and `RunScriptJob` builds the argv you expect from sample form input.
3. If the script is `type: Write`, add the negative-case tests proving the safety flow
   (§ below) cannot be bypassed for this script specifically.
4. Only touch the wrapped script itself if it has a "script contract" gap — see
   `PLAN.md` §5. That's a scoped fix to the script (e.g. a wrong exit code on partial
   failure), not new logic in DOWScripts.

## Coding standards

- Laravel Pint (PSR-12), run before every commit: `php8.2 vendor/bin/pint`
- Standard Laravel conventions: thin controllers, Form Requests for validation, Jobs for
  anything that shells out (never inline a `Process::run()` in a controller)
- Value objects (`ScriptDefinition`, `ParamDefinition`) are immutable — no setters

## Testing — strict TDD, no exceptions

Red-green-refactor is the expected workflow, not "add tests eventually."

**Tests never shell out to a real script or hit a real marketplace API.** Concretely:

- `ProcessRunner` is an interface; tests inject a fake and assert on the **exact argv and
  env** `RunScriptJob` built, never on a real process's output. If a test needs to run a
  real script to pass, that's a sign the test is checking the wrong thing — it should be
  asserting DOWScripts' behavior, not the script's (the script owns its own correctness
  and, ideally, its own tests in the parent repo).
- `ScriptRegistry`: unit tests that config loads correctly and that a malformed entry
  fails loudly at boot, not silently.
- Feature tests per route: dashboard renders, nav dropdowns list the right scripts for
  the current marketplace, a script's detail page renders the correct dynamic form from
  its `ParamDefinition`s, run history renders.
- **Write-confirmation flow** gets explicit negative tests: a write-type run cannot
  transition to "executed" without the exact matching confirmation text, and cannot skip
  the dry-run-first step. Write the test that proves the bypass is impossible, not just
  the test that proves the happy path works.
- Credentials: round-trip through encryption correctly in tests; use fake tokens, never
  real secrets, even in test fixtures.

A PR without tests covering its change doesn't ship, no exceptions for "it's just a
config entry" — the config entry is exactly what the registry tests exist to catch
mistakes in.

## Secrets handling

Never commit real credentials, anywhere, including test fixtures and `.env.example`
(which should only ever contain placeholder values). `MarketplaceCredential` uses
encrypted Eloquent casts — real tokens live in the database, not in code or `.env`,
once this model exists.
