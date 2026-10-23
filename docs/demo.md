[README](../README.md) | [Architecture](architecture.md)

# Run the demo

From the repository root:

```sh
make setup
make demo
make verify
```

`make demo` starts the app if needed, then runs `php artisan demo:run` in a
container. It uses the file sender's service to send in-memory bodies to the real
app endpoint. It requires the local demo database. It does not start `db-test`,
reset data or create body files. Each run adds two orders and fresh event/payment IDs.

| Step | HTTP response | Database state |
| --- | --- | --- |
| Success for A | 200 processed, duplicate=false | A paid, one payment and one processed receipt |
| Same bytes for A | 200 processed, duplicate=true | All rows and timestamps unchanged |
| Wrong sender key for B | 401 invalid_signature, one attempt | B pending, no payment or receipt; A unchanged |
| Same event ID, extra space | 409 event_conflict, one attempt | Both orders and all related rows unchanged |

The runner compares validated response fields and database rows after every step.
It checks the payment identity, amount, currency, receipt hash and final decision.
Replay checks include `paid_at`, `completed_at` and all stored row fields. Any
mismatch or exception returns a nonzero exit code and names the failed check.
Only this run's order, event and payment IDs are used for state checks.
The wrong key is limited to one send and is restored in `finally`.

To inspect a recorded order, use `demo:order:show` inside the container. It returns
order fields, `payment` and `receipts` as JSON. The UUID comes from the demo output.
You can also create a pending order with `demo:order:create 12990 EUR` and send a
raw body file with `demo:webhook:send`. The file sender prints status and attempt
count only, and exits 0 for 2xx. It does not dump the remote response.

## Concurrency check

The demo's four steps are sequential. To run one independent concurrency check:

```sh
make test ARGS="--filter test_c01_replays_identical_event_in_overlapping_processes"
```

Two child processes use separate PostgreSQL sessions. The test observes both
waiting on locks before releasing the blocker, then checks one receipt and one
payment. It runs on `db-test` and keeps demo data. See the
[architecture](architecture.md) for the other race cases and deadlines.

## Recorded run

The local run below returned exit 0 on 2026-10-23. Container progress lines are
omitted. The JSON after each status is the validated response summary.

```text
Orders: A=01a1691a-2853-72c6-a0ec-e932e82b2dc7; B=01a1691a-2857-716a-8749-5f9d2b28567e.
Event: evt_01a1691a-2857-716a-8749-5f9d2bce303f; payment: pay_01a1691a-2857-716a-8749-5f9d2bd6f8a0.
success: HTTP 200; {"event_id":"evt_01a1691a-2857-716a-8749-5f9d2bce303f","outcome":"processed","duplicate":false}; state checked.
replay: HTTP 200; {"event_id":"evt_01a1691a-2857-716a-8749-5f9d2bce303f","outcome":"processed","duplicate":true}; state unchanged.
signature: HTTP 401; {"error":"invalid_signature"}; state unchanged.
conflict: HTTP 409; {"error":"event_conflict"}; state unchanged.
A: paid; payments=1; receipts=1. B: pending; payments=0; receipts=0.
Demo checks passed.
```

The success request body is shown in the [README](../README.md). Its SHA-256 was
compared with the stored receipt. To inspect an order from your own run, pass its
printed UUID to `demo:order:show`:

```sh
docker compose run --rm --no-deps -T app php artisan demo:order:show 01a1691a-2853-72c6-a0ec-e932e82b2dc7
```

This recorded order was created in the verification stack and removed during
cleanup. In your own stack, use the UUID printed by your demo run.

## See also

- [API contract](openapi.yaml)
- [Retry decision](decisions/002-sync-flow.md)
- [Verification record](verification.md)
