# How I used AI agents

[README](../README.md) | [Verification](verification.md)

AI agents wrote most of the code, tests and docs here. I wrote the spec and the
acceptance matrix (48 checks with IDs like H03, D05 and C04), set the rules and
decided what to ship.

The agents worked in two roles. Implementer agents planned and built one stage
at a time. A coordinating agent reviewed each plan before any code, ran the
checks on the real Docker stack and sent defects back with the root cause.

## Checks for each stage

- `make verify`: Pint, PHPStan level 8 and the full suite on PostgreSQL.
  The run must show zero skipped tests.
- Signed `curl` requests to the running service, then the rows in all three tables.
- The full suite in random order.
- For race tests: break the code on purpose and expect a failing test.
- Before release: a fresh clone in a separate Compose project, `make setup`,
  `make demo`, `make verify`, and a scan of files and Git history for secrets.

## Green tests, wrong status

R03 asks for 503 when the database goes away during a request. The first
version passed its tests. Then the coordinating agent killed the database
session mid-request with `pg_terminate_backend()`. PDO reported `HY000`, a
general error. The classifier treated it as final, so the service answered 500.

The agent sent it back. With `HY000`, the fix now checks the original PDO
exception for a lost connection. A new test ends its own database session from
a trigger. It expects 503 and no new rows, then sends the same request again and
expects success. Commit `0b89fe3`.

## Testing the race tests

C01-C05 run two PHP processes with separate database sessions. Each test waits
until both processes block on the expected locks, then lets them go. To prove
these tests can fail, the coordinating agent removed `lockForUpdate()`, and all
five failed within six seconds. A plain `INSERT` instead of `ON CONFLICT` broke
C01 and C05 in under a second.

The same tests failed on my machine under heavy load too: two workers needed
more than five seconds to boot. That limit applied before any lock, so the
agent raised it and kept the lock limits as they were. Commit `bd8244a`.

## Other catches before commit

- Laravel's query builder drops microseconds from PHP dates. The coordinating
  agent caught it in plan review, before any code.
- A `"_method":"PUT"` field in a signed body turned the request into a 405.
  The app now ignores method override.
- `compose.yaml` has a fixed project name, so a clean clone would reuse the
  demo database. The clean-checkout run uses its own project name, port and
  image tag.

My prompts and agent setup are not in this repository.
