[Idempotency](001-idempotency.md) | [README](../../README.md) | [Verification](../verification.md)

# 002: Process during the HTTP request

Status: accepted.

## Decision

The receiver processes each event before sending its HTTP response. One action
owns the database transaction. There is no queue or external call inside it.
This keeps the demo easy to run and makes the commit boundary clear.

Before commit, an exception rolls back the receipt, payment and order changes.
Unexpected failures return 500 `internal_error`. A database connection failure,
lock timeout, statement timeout or exhausted concurrency retry returns 503
`temporarily_unavailable`. No successful acknowledgement is sent on these paths.

After commit, the response can be lost. The sender then retries the exact body.
The receiver reads the committed receipt and returns its stored decision with
`duplicate=true`, without a second payment or a new `paid_at`.

## Two retry budgets

| Layer | Default policy |
| --- | --- |
| Receiver transaction | At most 3 attempts for SQLSTATE 40001 or a detected deadlock (40P01) |
| Receiver SQL | Local lock timeout 3000 ms; statement timeout 10000 ms |
| Sender delivery | At most 3 attempts for network errors or HTTP 500, 503, 429 |
| Sender wait | 250 ms, then 500 ms by default; exponential backoff capped at 2000 ms |
| Sender request | 15 s total per attempt; 2 s connect timeout; no redirects |

The receiver does not retry lock or statement timeouts inside the same request.
The sender stops on other HTTP statuses. In particular, 401 and 409 are terminal.
The receiver does not currently emit 429; the sender can handle it.

The sender reads its body file once, keeps those bytes, and calculates a timestamp
and HMAC for each attempt. Attempts in the same second can have the same timestamp.
The key is used as a literal string. It is not decoded from hex.

The local [demo runner](../../app/Console/Commands/RunDemoCommand.php) uses the same
sender service with in-memory bodies. It checks status, validated response fields
and database state after each delivery. Only the sender's in-process key changes
for the invalid-signature step, and `finally` restores it. The receiver key stays
unchanged. No response body is dumped by the file sender command. Its optional
service result holds only validated event ID, outcome, duplicate, reason and error
fields, with a 4096-byte and JSON-depth limit of 8.

## Evidence and limits

The [receiver failure tests](../../tests/Feature/Webhooks/DemoPayFailureHandlingTest.php)
prove rollback and replay after an observed commit. A separate
[sender test](../../tests/Feature/Console/SendDemoWebhookCommandTest.php)
simulates a lost response and checks retry bytes and signatures. Together they
cover the two sides of response loss; they do not cut a real network connection.

Email, shipment and external APIs cannot be rolled back by PostgreSQL. Adding them
would need a separate delivery mechanism, such as a transactional outbox with its
own retry and deduplication policy. This version has no outbox. It also has no
refund processing, partial payments, key rotation or multiple providers.

## See also

- [Architecture](../architecture.md)
- [Sender service](../../app/Services/SendDemoWebhook.php)
- [Timeout tests](../../tests/Feature/Webhooks/DemoPayTimeoutTest.php)
