[Architecture](../architecture.md) | [README](../../README.md) | [Synchronous flow](002-sync-flow.md)

# 001: Event and payment identity

Status: accepted.

## Decision

An event ID identifies one provider message. A payment ID identifies one payment.
They serve different purposes. A provider can report the same payment under a new
event ID. We store both identities and use PostgreSQL unique constraints:

| Table | Unique key | Purpose |
| --- | --- | --- |
| webhook_receipts | provider, event_id | Keep one final decision per event |
| payments | provider, provider_payment_id | Keep one payment identity |
| payments | order_id | Allow at most one payment per order |

For the same event and the same raw bytes, return the saved status, outcome and
reason with `duplicate=true`. This includes ignored and rejected decisions.
Changes to an order do not change an old decision. A new event for the same
payment, order, amount and currency returns `duplicate_payment` with
`duplicate=false`. It creates a receipt but leaves the payment and `paid_at` alone.

The body identity is its SHA-256 hash. Spaces and JSON key order change that hash.
Valid signed bytes with an old event ID and a different hash return 409
`event_conflict`. The old receipt remains unchanged. The timestamp and signature
are not part of event identity. A retry can sign old bytes with a current timestamp.

## Locks and conflicts

[ProcessWebhook](../../app/Actions/ProcessWebhook.php) acquires resources in this order:

1. Claim the receipt slot with `ON CONFLICT (provider, event_id) DO NOTHING RETURNING`.
2. If the event already exists, read it in a new statement and compare hashes.
3. For a new payment event, lock its order with `SELECT FOR UPDATE`.
4. Check order existence, amount, currency, payment identity, then order status.
5. Insert with `ON CONFLICT (provider, provider_payment_id) DO NOTHING RETURNING`.
6. If another transaction won the payment ID, read it in a new statement and classify it.
7. Update the order and finalize the receipt before commit.

Read Committed gives the separate read a fresh snapshot of the committed winner.
We do not catch every insert error as a duplicate. Other constraint errors abort
the transaction. The order lock protects competing payments for one order. The
payment unique key protects a payment ID used for different orders.

## Limits

We chose byte identity because it is small and clear for this fictional contract.
We do not canonicalize JSON. A real provider contract may need another policy.
There is one shared HMAC key and no key rotation in this version.

Constraints enforce uniqueness and valid row values. The application transaction
keeps the processed receipt, payment and paid order together. Direct SQL can still
create a paid order without a payment; no cross-table CHECK enforces that count.
The guarantee covers changes in this single database.

## See also

- [Migrations](../../database/migrations/)
- [Replay tests](../../tests/Feature/Webhooks/DemoPayReplayTest.php)
- [Concurrency tests](../../tests/Feature/Webhooks/DemoPayConcurrencyTest.php)
