> You are in **splicewire/laravel-beam-dev** — session-scoped scratch databases for Laravel development and testing.

Two commands: `splicewire:beam:dev:isolated-test-db` creates a database nobody else is using and
prints the env that targets it; `splicewire:beam:dev:drop-db` reaps one, several, or a prefix-scoped
sweep. Engine-aware (pgsql / mysql / sqlite), and it borrows credentials from an existing Laravel
connection rather than asking for its own.

## Standalone on purpose

Despite the name this requires **nothing** from the beam family — the `splicewire:beam:dev:` prefix
is naming, not coupling. Keep it that way. It is a public package whose whole value is being trivial
to install in any Laravel project, and a dev tool that drags a framework in behind it does not get
installed. If something here starts wanting a beam class, that is a sign the feature belongs in a
beam package instead.

## This package deletes databases

That constraint outranks every other consideration in the repo.

All drop policy lives in ONE place, `Databases\DropGuard::refuse()`, and it returns a reason rather
than a bool so the caller can tell the user what to do about it. Do not add a second decision point —
a scattered guard is a guard nobody can audit. `Databases\ScratchDatabases` is deliberately dumb
mechanism: it will drop whatever it is told to, which is exactly why nothing may call it without
asking the guard first.

Two of the four rules are **not** overridable by `--force`, and tests pin both:

- **production** — never, under any flag.
- **live sessions on the target** — `--force` is for naming a database outside the scratch prefix, not
  for yanking one out from under a running suite. That failure mode is the reason this package
  exists; re-introducing it via a flag would be self-defeating.

Database names are interpolated into DDL (they cannot be bound), so they are validated against an
identifier charset and rejected, never escaped.

## Testing

The suite runs on SQLite over a temp directory, so it exercises the real create / list / drop paths
with no database server in CI. The guard has a test per rule, including the two force cannot override.
When adding an engine branch, assert its SQL by inspection rather than reaching for a live server.

## Vendored family-package conventions

Any repo that vendors another family repo's code (composer `vendor/<vendor>/<pkg>/`, npm
`node_modules/<vendor>/<pkg>/`) checks that vendored repo's own `AGENTS.md` for conventions it
ships with itself before editing through into it.
