[Demo](demo.md) | [README](../README.md) | [Architecture](architecture.md)

# Verification record

This is a local source snapshot check. It is not a verified release commit.
The remaining release checks are listed below.

## Source provenance

Checked on 2026-10-23, from 14:13:33 to 14:15:22 UTC.

Base commit: `bd8244aacfe60825cddd71bbb66ac9a8113dc193`.
A fresh local clone received the public files below as an explicit source overlay.
The clone had no copied `.env`, dependencies, caches or runtime data. Setup created
its secrets and installed dependencies from `composer.lock`. Docker build layers
were cached. Host PHP and Composer were not used.

The overlay was checked byte for byte before execution and after restoring the
mutation. Its manifest SHA-256 is
`12eeb08a45bac0559e8f84a747a846e131778937018b5ea57d7eed43c4444f5e`.
The manifest contains sorted `SHA256  path` lines with a final newline for:

```text
.gitignore
Dockerfile
LICENSE
Makefile
README.md
app/Console/Commands/RunDemoCommand.php
app/Data/WebhookDeliveryResult.php
app/Services/SendDemoWebhook.php
compose.yaml
docs/architecture.md
docs/decisions/001-idempotency.md
docs/decisions/002-sync-flow.md
docs/demo.md
docs/openapi.yaml
tests/Feature/Console/RunDemoCommandTest.php
tests/Feature/Console/SendDemoWebhookCommandTest.php
```

This record was written after execution and is outside that manifest. The base
commit does not contain the overlay. A clean clone of a committed version with
these changes is still required for final P01 evidence. That later check must
name the code commit it ran, rather than a later commit containing this record.

## Runtime

| Command in the container | Actual output |
| --- | --- |
| `php -v` | PHP 8.5.12 (cli), built Oct 21 2026 15:53:40, NTS; Zend Engine 4.5.12 and OPcache 8.5.12 |
| `php artisan --version` | Laravel Framework 13.38.0 |
| `composer --version` | Composer version 2.10.3 2026-08-27 13:34:23 |
| `vendor/bin/phpunit --version` | PHPUnit 12.5.40 |
| `SHOW server_version` | 18.6 (Debian 18.6-1.pgdg13+2) |
| `uname -m` | aarch64 |

Runtime checks ran on Linux arm64 in Docker. The registry index for each base
image includes linux/amd64 and linux/arm64. No local amd64 execution is claimed.
Digests came from `docker buildx imagetools inspect` on each tag:

| Tag | Multi-architecture index digest |
| --- | --- |
| `php:8.5.12-cli-trixie` | `sha256:595a7cbb1e0547336319f228c4dc991c2a199350ab12b1c8fda05f73abd06e6b` |
| `composer:2.10.3` | `sha256:b6dcd72ca98fa19d0e5b8476de179859704891fad06dd9e0b96833d4c430a5fc` |
| `postgres:18.6-trixie` | `sha256:edb0c08dc33befd0262ab3b5cf1c4f3f8cfd8b09e88c05b45adee29d6761ddb6` |

The [Dockerfile](../Dockerfile) and [Compose file](../compose.yaml) pin these
indexes. CI uses the same files. Runtime package downloads during the Docker build
are not pinned by the image digest.

## Commands and results

The snapshot results below predate the bounded sender response-read fix and its
new regression tests. They do not describe the current working tree. A clean
committed-checkout run is still required after the code commit.

`CHECKOUT` below names the temporary clone directory inside the source repository.
Its local path is omitted. The same three overrides applied to every Make and
Compose command, including cleanup:

```sh
git clone --no-hardlinks . "$CHECKOUT"
# Copy only the public overlay listed above into the clone.
cd "$CHECKOUT"
export COMPOSE_PROJECT_NAME=lpw-s5-clean-20261023
export APP_PORT=18088
export APP_IMAGE=lpw-s5-clean-20261023:app
make setup
make demo
make demo
make setup
make verify ARGS="--log-junit storage/framework/testing/junit.xml"
docker compose run --rm --no-deps -T app composer audit --locked --no-interaction
docker compose run --rm --no-deps -T app sh -c 'php -v && php artisan --version && composer --version && vendor/bin/phpunit --version && uname -m'
docker compose exec -T db sh -c 'psql -U "$POSTGRES_USER" -d "$POSTGRES_DB" -At -c "SHOW server_version"'
```

Each command above returned exit 0. The free port, project labels, app image tag,
bind mount and demo volume were checked before test cleanup ran.
Setup and demo started only `app` and `db`. Verification started the separate
`db-test` service with its own credentials and tmpfs data.

| Gate | Actual result |
| --- | --- |
| Composer validation | Passed with `--strict` |
| Pint | Passed, 84 files |
| PHPStan | Passed, level 8, no baseline; explicit 512 MB analysis limit |
| PHPUnit | 530 tests, 6304 assertions, 0 errors, 0 failures, **0 skipped** |
| Test duration | 17.37 s in the CLI output; 16.887790 s in JUnit |
| Composer audit | Exit 0: no security vulnerability advisories found |
| Repeated demo | Exit 0 twice; four orders, two payments, two receipts; fresh IDs and unchanged first-run rows |
| Repeated setup | Exit 0; complete `.env` bytes and all demo rows unchanged |
| Test isolation | All demo rows unchanged after verification |

An earlier fresh analysis hit PHP's default 128 MB memory limit. The Make command
now gives PHPStan 512 MB. This changes the analyzer process only. The full final
verification above passed with that command.

C01-C05 all passed on PostgreSQL, with no skips or timeouts. Their JUnit group
contains 5 tests, 1362 assertions and 0 errors/failures/skips. The tests prove
process and session overlap before checking the results. See the
[concurrency helper](../tests/Support/ConcurrentWebhookRunner.php).

### Bounded response-read check

On 2026-10-23, after limiting sender summary reads to 4097 bytes, verification ran
in the current working tree with the existing app image and a separate Compose
project and test database:

```sh
COMPOSE_PROJECT_NAME=lpw-s5-rework-a-20261023 APP_PORT=18089 make verify ARGS="--log-junit storage/framework/testing/rework-a-junit.xml"
```

Exit 0: Composer validation, Pint and PHPStan passed; PHPUnit reported 542 tests,
6608 assertions, 0 errors, 0 failures and **0 skipped**. The new tests cover
1 MiB and 32 MiB responses in normal and response-exception paths, short stream
reads, the 4096-byte boundary, unchanged retries, logs and command exit codes.
The earlier snapshot's manifest and results above remain historical evidence.
This check did not rerun setup, the HTTP demo or P01; the committed-checkout check
is still required.
The isolated test resources were removed. Existing container IDs, health, demo
rows, app image, demo-volume metadata and `.env` bytes matched before and after.

## Manual checks

### P01_clean_checkout_setup_demo_verify

Passed for the source snapshot described above. The strict committed-checkout
check remains pending. No existing `.env` or `vendor` directory was reused.

### P04_demo_repeat_and_failed_expectation

Both normal runs returned exit 0 with fresh order, event and payment IDs. Each
response and related database state was checked. Rows from the first run remained
unchanged after the second run, setup and verification.

In the temporary clone only, the first success expectation was changed from
`httpStatus === 200` to `httpStatus === 201`. Running `make demo` returned exit 2
(the command returned 1), with `Demo failed: success_response.`. The original file
was restored byte for byte. The next `make demo` returned exit 0.
The failed run's rows were local to the temporary demo database.

### P06_openapi_http_contract_review

The [OpenAPI file](openapi.yaml) was read beside the route, middleware, decoder,
controller, error renderer and HTTP tests. Checked items include:

- POST, JSON responses without Accept, GET/PUT 405 and `Allow: POST`.
- The 65536-byte boundary, media type/charset, timestamp format/window and raw-byte HMAC.
- Object envelopes, strict integer tokens, IDs, UUIDs, currencies and extra fields.
- Unknown event types, saved successful/rejected/ignored decisions and replay.
- Both 409 shapes, all reason codes, 400/401/413/415/422/500/503 and rollback behavior.

The 500/503 descriptions now include transaction rollback, database unavailability,
timeouts and exhausted concurrency retries. JSON Schema's integer type cannot
express rejection of decimal/exponent tokens; the contract states this limit.
429 belongs to sender retry handling and is not claimed as a receiver response.
All associated HTTP tests passed in the full run.

### P08_source_archive_hygiene

The tracked source list and the new public files were checked together, without
staging. No real local secrets, Cyrillic text, private working paths, local context
references, unfinished markers or runtime files were found in public content.
Local-tool ignore rules remain intact. Standard dependency metadata and crawler
directives retain their names; they are not local context records.

A temporary source snapshot archive was built from that public list using the
current export-ignore rules. It contains source, tests, documentation, LICENSE,
Make/Docker files and `composer.lock`. It has no `.env`, `vendor`, database data,
logs or local configuration data. `.env.example` contains empty secret values.
No export-ignore change was needed. This is a snapshot archive, not a GitHub
release ZIP. A committed source archive still needs the final code commit.

### P09_readme_links_and_code_review

Relative documentation links and anchors were checked against existing files.
README paths, the request/response example, diagrams and both decisions were
compared with the code. The example body hash was matched to its real receipt.
The text uses English and has no invented badge, usage or performance claims.

## Isolation and cleanup

Before and after the run, the owner's existing container IDs, health, mounts,
volume metadata, image tag, `.env` bytes and demo-row contents were compared.
All comparisons matched. No reset or migration ran against that database.

Cleanup used the same overrides and the exact temporary project name:

```sh
make down
docker compose -p lpw-s5-clean-20261023 down -v --remove-orphans
docker image rm lpw-s5-clean-20261023:app
```

Both shutdown commands returned exit 0. The temporary clone was removed after the
safe results and JUnit report were retained. No temporary project containers,
volumes or app image tag remain. The earlier iteration stack was also removed.
The existing owner stack remains healthy with its original data.

## Acceptance coverage

Every automated check below ran in the full PHPUnit suite. Method names identify
the exact check; parameterized methods include all their data-provider cases.

| ID | Test or manual check | Result |
| --- | --- | --- |
| H01 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h01_passes_verified_dto_to_action`, `test_h01_uses_secret_as_literal_string`; [validation](../tests/Feature/Webhooks/DemoPayValidationTest.php): `test_h01_accepts_payment_boundaries_and_ignores_extra_fields` | Passed |
| H02 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h02_rejects_invalid_signature`, `test_h02_rejects_foreign_secret`, `test_h02_rejects_multiple_header_values` | Passed |
| H03 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h03_rejects_body_changed_after_signing` | Passed |
| H04 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h04_rejects_noncanonical_timestamp`, `test_h04_accepts_zero_at_epoch` | Passed |
| H05 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h05_checks_inclusive_timestamp_window` | Passed |
| H06 | [Signature](../tests/Feature/Webhooks/DemoPaySignatureTest.php): `test_h06_rejects_missing_secret` | Passed |
| H07 | [Validation](../tests/Feature/Webhooks/DemoPayValidationTest.php): `test_h07_rejects_invalid_json` | Passed |
| H08 | [Validation](../tests/Feature/Webhooks/DemoPayValidationTest.php): `test_h08_rejects_invalid_envelope`, `test_h08_accepts_unknown_type_with_object_data`, `test_h08_accepts_maximum_type_length_and_all_allowed_characters` | Passed |
| H09 | [Validation](../tests/Feature/Webhooks/DemoPayValidationTest.php): `test_h09_rejects_non_strict_amount` | Passed |
| H10 | [Validation](../tests/Feature/Webhooks/DemoPayValidationTest.php): `test_h10_rejects_invalid_payment_fields` | Passed |
| H11 | [HTTP contract](../tests/Feature/Webhooks/DemoPayHttpContractTest.php): `test_h11_enforces_byte_limit`, `test_h11_rejects_unsupported_media_type`, `test_rejection_precedence` | Passed |
| H12 | [HTTP contract](../tests/Feature/Webhooks/DemoPayHttpContractTest.php): `test_h12_accepts_charset_without_accept`, `test_h12_returns_json_for_unsupported_methods`, `test_h12_ignores_method_override_for_signed_posts`, `test_h12_rejects_unsigned_posts_with_method_override` | Passed |
| H13 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_h13_records_unknown_type_with_empty_data_as_ignored` | Passed |
| D01 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d01_processes_pending_order_atomically`, `test_d01_applies_configured_timeouts_before_claiming_receipt` | Passed |
| D02 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d02_replays_processed_event_without_mutating_rows` | Passed |
| D03 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d03_replays_same_bytes_with_fresh_timestamp` | Passed |
| D04 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d04_rejects_changed_body_for_existing_event` | Passed |
| D05 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d05_records_duplicate_payment_without_rewriting_payment_or_order`; [replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d05_replays_duplicate_payment_receipt_without_changes` | Passed |
| D06 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d06_rejects_new_payment_for_paid_order` | Passed |
| D07 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d07_rejects_payment_id_bound_to_another_order` | Passed |
| D08 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d08_rejects_missing_order`; [HTTP contract](../tests/Feature/Webhooks/DemoPayHttpContractTest.php): `test_d08_default_action_records_missing_order_rejection` | Passed |
| D09 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d09_selects_first_business_rejection` | Passed |
| D10 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d10_replays_rejected_decision_after_order_changes` | Passed |
| D11 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d11_preserves_missing_order_rejection_then_accepts_new_event` | Passed |
| D12 | [Replay](../tests/Feature/Webhooks/DemoPayReplayTest.php): `test_d12_replays_ignored_event_then_rejects_changed_bytes` | Passed |
| D13 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d13_ignores_refund_after_successful_payment` | Passed |
| D14 | [Processing](../tests/Feature/Webhooks/DemoPayProcessingTest.php): `test_d14_processes_independent_orders_and_payments` | Passed |
| D15 | [Constraints](../tests/Feature/Database/DomainConstraintsTest.php): all `test_d15_*` methods and providers; [migration round trip](../tests/Feature/Database/MigrationRoundTripTest.php): `test_d15_migrate_rollback_migrate_restores_domain_constraints` | Passed |
| R01, R02 | [Failure handling](../tests/Feature/Webhooks/DemoPayFailureHandlingTest.php): `test_r01_r02_rolls_back_all_writes_then_processes_same_event` | Passed |
| R03 | [Failure handling](../tests/Feature/Webhooks/DemoPayFailureHandlingTest.php): `test_r03_returns_503_for_real_refused_connection_and_recovers`, `test_r03_returns_503_when_backend_terminates_during_processing_and_recovers` | Passed |
| R04 | [Failure handling](../tests/Feature/Webhooks/DemoPayFailureHandlingTest.php): `test_r04_replays_committed_result_after_simulated_response_loss`; [sender](../tests/Feature/Console/SendDemoWebhookCommandTest.php): `test_r04_retries_after_simulated_response_loss_with_same_body_and_fresh_signature` | Passed; separate receiver and transport-fake checks |
| R05 | [Timeouts](../tests/Feature/Webhooks/DemoPayTimeoutTest.php): `test_r05_times_out_on_order_lock_then_processes_same_event`, `test_statement_timeout_returns_503_without_partial_writes` | Passed |
| C01 | [Concurrency](../tests/Feature/Webhooks/DemoPayConcurrencyTest.php): `test_c01_replays_identical_event_in_overlapping_processes` | Passed |
| C02 | [Concurrency](../tests/Feature/Webhooks/DemoPayConcurrencyTest.php): `test_c02_deduplicates_payment_for_same_order_in_overlapping_processes` | Passed |
| C03 | [Concurrency](../tests/Feature/Webhooks/DemoPayConcurrencyTest.php): `test_c03_rejects_second_payment_for_same_order_in_overlapping_processes` | Passed |
| C04 | [Concurrency](../tests/Feature/Webhooks/DemoPayConcurrencyTest.php): `test_c04_rejects_shared_payment_for_different_orders_in_overlapping_processes` | Passed |
| C05 | [Concurrency](../tests/Feature/Webhooks/DemoPayConcurrencyTest.php): `test_c05_rejects_changed_body_for_same_event_in_overlapping_processes` | Passed |
| P01 | `P01_clean_checkout_setup_demo_verify` | Snapshot passed; committed checkout pending |
| P02 | Repeated setup, `.env` and demo-row comparison above | Passed |
| P03 | [Database guard](../tests/Feature/Support/TestDatabaseConnectionTest.php): `test_p03_uses_real_webhooks_test_database`; [reset guard](../tests/Feature/Console/ResetDemoCommandTest.php): both `test_p03_*` methods; manual demo-row comparison | Passed |
| P04 | [Demo runner](../tests/Feature/Console/RunDemoCommandTest.php): all `test_p04_*` methods; `P04_demo_repeat_and_failed_expectation` | Passed |
| P05 | GitHub CI on the final published code commit | Pending; no remote run claimed |
| P06 | `P06_openapi_http_contract_review` and H/D/R HTTP tests | Passed |
| P07 | [Logging](../tests/Feature/Webhooks/DemoPayLoggingTest.php): all methods; [sender summary](../tests/Feature/Console/SendDemoWebhookCommandTest.php): all `test_p07_*` methods | Passed |
| P08 | `P08_source_archive_hygiene` | Public snapshot passed; committed archive pending |
| P09 | `P09_readme_links_and_code_review` | Passed |
| P10 | Owner's spoken demonstration and explanation | Pending |

[Database retry tests](../tests/Feature/Webhooks/DemoPayDatabaseRetryTest.php) also
passed for transient retry limits and nonretryable errors. No publication, tag or
release was made during this check.

## See also

- [Demo transcript](demo.md)
- [Architecture and logs](architecture.md)
- [API contract](openapi.yaml)
