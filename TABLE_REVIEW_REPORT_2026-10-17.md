# Updated table and API compatibility review

Reviewed: 2026-10-17. Compared with [the earlier checklist](TABLE_REVIEW_CHECKLIST.md), [implementation plan](IMPLEMENTATION_PLAN.md), and [database proposal](DATABASE_STRUCTURE.md).

The schema has improved, but the current tables and application code still cannot support the complete deposit, withdrawal, betting, settlement, and history flow together. The highest priorities are the wallet history constraint, incompatible payment code, incomplete withdrawal requests, and migration rollbacks.

This report records the current working tree. It does not implement fixes. The earlier checklist and planning documents remain unchanged. References to the video come from the saved plans; the video was not reviewed again.

## Changes since the earlier checklist

| Earlier finding | Current evidence | Status |
| --- | --- | --- |
| 3D bet numbers limited to two characters | `threed_bets.draw_number` is now length 3 | Resolved for this column; result storage and factories still need changes |
| Bets have no stake or payout amounts | Both bet tables now have `stake_amount`, `potential_payout`, and `settle_amount` | Partially resolved; model/factory compatibility and settlement identity remain open |
| Draws have no payout multiplier | `twod_draws.odds` defaults to 85; `threed_draws.odds` defaults to 550 | Column added; accepted-rate snapshots, validation, and business approval remain open |
| 3D result numbers limited to two characters | `threed_draws.winning_number` is still length 2 | Open |
| One movement permitted per wallet | `wallet_transactions.wallet_id` is still unique | Open |
| Withdrawal requests lack amount/destination and require both reviewers | Definitions remain unchanged | Open |
| Payment service expects the older accounting schema | Service still uses `payment_transactions`, `users.balance`, and older ledger fields | Open |
| Draw lifecycle, cutoff, and unique identity gaps | No lifecycle status or unique draw identity; 2D still lacks opening/cutoff times | Open |
| Implicit monetary precision | Money and odds still use bare `decimal()` | Open; new bet amounts have the same precision issue |
| Wallet ownership/defaults | Owner is not unique; balances have no defaults | Open |
| Incomplete rollback | Payment/game `down()` methods remain incorrect | Open; role rollback also needs correction |
| Shared purchase identity and account snapshots | No slip header or immutable payment-account snapshot fields | Open |
| Result models expect missing tables | Result models still target tables absent from current migrations | Open unless these models are intentionally retired |
| UUID routes, support fields, relationships, tests | Several remain inconsistent; additional examples appear below | Open |

The older planning documents also describe missing `users.role_id` migration and stale route caches. The role migration now exists. The cache directory currently has no route or configuration cache file, so those older observations must not be reported as current defects.

## Critical findings

### F01. Wallet history still allows only one movement per wallet

Source: [payment/wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php), lines 159-179.

`wallet_transactions.wallet_id` is unique. With that constraint applied, one deposit movement would prevent a second deposit, bet, or refund movement for the same wallet. The column has no foreign key to `wallet.id`. `idempotency_key` exists but has no uniqueness constraint.

- [ ] Remove uniqueness from `wallet_id`, retain an index, and add its foreign key.
- [ ] Choose the business scope of the retry key and enforce uniqueness at that scope.
- [ ] Make balance updates, source records, and ledger insertion one atomic operation; reject changed-payload reuse of a key.

### F02. The existing payment service cannot use the current schema

Sources: [PaymentService](app/Service/PaymentService.php), particularly lines 35-69 and 141-168; [payment model](app/Models/PaymentTransactions.php); [transaction model](app/Models/Transaction.php); [current migration](database/migrations/2026_10_17_121133_create_transaction_table.php).

| Existing code expects | Current schema provides |
| --- | --- |
| `payment_transactions` for requests | No create-table definition for that table |
| `users.balance` as spendable funds | Separate `wallet.avaliable_balance` and `locked_balance`; no `users.balance` column |
| `transactions.user_id`, `payment_transaction_id`, `balance_before`, `balance_after` | `transactions` now stores payment details under a different structure |
| Active payment methods through `payment_methods.status` | No method `status` column |
| `withdrawal`, `rejected`, and `cancelled` payment semantics | Different `withdraw` and status enums across the new tables |

`PaymentTransactions::paymentMethod()` also calls `withTrashed()`, although the current `PaymentMethod` does not use `SoftDeletes`. `User::payments()` still targets the missing payment table.

- [ ] Select one authoritative accounting design, then align its migrations, models, services, API contract, and tests.
- [ ] Define how existing deployed data would be preserved before replacing or rewriting migrations.
- [ ] Preserve one balance source and consistent lock order across every money writer.

The separate wallet design can work, but adding these tables does not adapt the older service automatically.

### F03. Pending withdrawal requests still cannot record a complete request

Source: [payment/wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php), lines 92-142.

`withdraw_requests` has no amount or destination account, and `transaction_id` is nullable. Both `approve_user_id` and `declined_user_id` are required, even for a newly requested withdrawal. The schema therefore cannot represent an unreviewed request without supplying reviewer IDs prematurely, and it permits a request without an identifiable amount/destination.

- [ ] Store the amount and destination snapshot on submission, or require a linked record that contains them.
- [ ] Keep review identities nullable until their respective actions occur.
- [ ] Define request, approval, transfer completion/failure, rejection, and cancellation transitions.
- [ ] Define available-to-locked movement, consumption on completion, and release on rejection/failure so retries cannot debit or release funds twice.

### F04. Bare decimals cannot hold ordinary larger payouts reliably

Sources: [payment/wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php); [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php), lines 21-38; [2D migration](database/migrations/2026_10_17_125733_create_twodbet_table.php), lines 19-37.

The installed Laravel 13.35.0 defines bare `decimal()` as precision 8, scale 2, confirmed in local `vendor/laravel/framework/src/Illuminate/Database/Schema/Blueprint.php:1157`. Where enforced, its positive maximum is `999,999.99`.

For example, a 3D stake of `2,000` multiplied by the configured 550 odds produces `1,100,000`, already beyond the new `potential_payout` and `settle_amount` range. The same limit affects wallet balances and payment amounts. SQLite does not establish production precision enforcement.

- [ ] Choose explicit supported ranges for stakes, payouts, balances, and odds.
- [ ] Apply one money-unit policy consistently; the saved plan proposes whole MMK, while the new transaction model uses two decimal places.
- [ ] Validate positive amounts, nonnegative balances, overflow, and payout rounding using exact arithmetic.

## High-priority findings

### F05. Three-digit results are still incompatible with the schema and examples

Sources: [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php), lines 21 and 35; [entry factory](database/factories/ThreedEntriesFactory.php), line 22; [draw factory](database/factories/ThreedDrawFactory.php), line 18; [schema test](tests/Feature/ModelSchemaTest.php), lines 15-29.

The bet column now supports three characters, but the draw result remains length 2. Both 3D factories still generate two digits, and the shared schema test checks `00` and `01` for both games. These examples would not catch loss of a genuine 3D value such as `007`.

- [ ] Use length 3 for the 3D winning number and exactly three ASCII digits at the input boundary.
- [ ] Update 3D examples and assertions to cover `000`, `007`, and `999`; keep 2D examples two digits.

### F06. New bet amounts are missing from model/factory definitions

Sources: [2D model](app/Models/TwodEntry.php), [3D model](app/Models/ThreedEntry.php), [2D factory](database/factories/TwodEntriesFactory.php), [3D factory](database/factories/ThreedEntriesFactory.php), and both game migrations.

Both migrations require `stake_amount`, `potential_payout`, and `settle_amount`, with no defaults. Neither entry model includes these fields in its `Fillable` attribute or money casts, and neither factory supplies them. Normal mass assignment will not populate the new fields; default factory inserts also omit required values.

- [ ] Align entry fields, exact money casts, factories, and requests with the new columns.
- [ ] Define whether unsettled `settle_amount` is zero or null and represent that consistently.
- [ ] Keep the accepted odds snapshot alongside the bet; mutable draw odds alone cannot explain the original purchase terms. Both draw models also omit `odds` from their fillable fields.

### F07. Bets still have no reliable draw or purchase relationship

Sources: [2D migration](database/migrations/2026_10_17_125733_create_twodbet_table.php), [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php).

Dates and session strings are copied into bets, but there is no `draw_id` foreign key, unique request key, shared receipt/slip, accepted odds column, or settlement timestamp. Amount columns alone do not establish which draw settles a bet or whether a payout/refund already happened.

- [ ] Link each bet to its matching game draw and retain accepted financial terms.
- [ ] Store settlement time and enforce one financial effect per settlement/refund source.
- [ ] For multi-number checkout, create a shared purchase identity and accept all entries plus the total debit atomically.

### F08. Draw lifecycle and cutoff rules are still incomplete

Sources: both game migrations and the draw models.

Both draw tables lack lifecycle status and a unique draw identity. The 2D table requires a winning number before publication and has no opening/cutoff fields. The 3D table has time strings, but these alone do not define timezone, acceptance state, or settlement completion.

- [ ] Allow an unpublished 2D result to be null.
- [ ] Define opening, cutoff, publication, settlement, cancellation, and timezone rules.
- [ ] Enforce unique business draw identity after confirming the 3D calendar/session rules.
- [ ] Recheck draw state and server cutoff inside the same transaction as bet acceptance and debit.

The 85/550 defaults are present in source; whether they include returned stake still requires a business decision.

### F09. Payment and game rollback methods remain incorrect

Sources: payment migration lines 186-188; 3D migration lines 57-59; 2D migration lines 54-56; [role migration](database/migrations/2026_10_09_051034_add_role_id_column_to_user_table.php), lines 25-27.

- Payment migration creates six tables but drops only `transactions`; the surviving withdrawal foreign key can also block that drop.
- Game rollbacks drop `threedbet` and `twodbet`, rather than the actual bet and draw tables.
- Role rollback passes `role_id` as a constraint name to `dropForeign()`, rather than its generated constraint name or column array. It then calls `removeColumn()`, which removes a pending blueprint definition rather than issuing a database column drop.

- [ ] Reverse every created table in dependency-safe order.
- [ ] Use the installed Laravel APIs that actually drop the role foreign key and column.
- [ ] Verify rollback and reapplication on an isolated database after correction.

### F10. Declared admin routes reference missing controllers; UUID routes reject UUIDs

Source: [API routes](routes/api.php), lines 3-7 and 30-36; current controller inventory.

`App\Http\Controllers\admin\PaymentMethodController` and `App\Http\Controllers\admin\TransactionController` are referenced but absent. The read-only command `php artisan route:list --path=api --except-vendor --no-interaction` exited with a `ReflectionException` naming the missing `PaymentMethodController`. This is an observed command failure, not an HTTP test result.

The admin item routes still use `whereNumber()` for UUID identifiers. Customer payment-method, payment submission/history/cancellation, and wallet endpoints expected by the saved plan/tests are absent from the current route source. There are also no betting or settlement endpoints in that file.

Auth/support route imports use lowercase `web`, while the corresponding files are under `Controllers/Web`; `SupportController` declares lowercase `web` too. Windows may mask this mismatch; it needs consistent path/namespace casing for deployment to a case-sensitive filesystem.

- [ ] Supply compatible controllers for retained routes and align the customer API with the agreed schema.
- [ ] Use UUID-compatible route constraints and consistent directory/namespace casing.
- [ ] Rerun route inspection, then verify authenticated HTTP behavior after implementation.

### F11. Support model and tests still target the older contact fields

Sources: [Support model](app/Models/Support.php), line 10; [support migration](database/migrations/2026_10_17_133112_create_support_table.php), lines 32-35; [resource](app/Http/Resources/SupportResource.php); [tests](tests/Feature/SupportTest.php).

The migration, seeder, and resource use `type` and `value`. The model permits `link` and `additional_informations` instead. Ordinary model writes can discard the current fields or try to update nonexistent older columns. Support tests still update `link`, so they do not match the current contact contract. Standard Artisan seeding runs unguarded; successful seeding would not prove ordinary model mass assignment works.

- [ ] Align fillable fields, validation, and test assertions with `type` and `value`.
- [ ] Verify phone/link values and active-contact output under the selected contract.

### F12. Master-data versions do not cover payment settings returned to callers

Sources: [MasterDataSeeder](database/seeders/MasterDataSeeder.php), lines 18-28; [MasterService](app/Service/MasterService.php), lines 16-26; [test](tests/Feature/MasterDataTest.php), lines 38-44.

Payment methods are commented out of the hash input, leaving only support types. The master endpoint nevertheless uses that hash to skip the complete response when the client supplies the same version. Changing a payment description or service hours and rerunning the seeder can therefore leave the version unchanged and cause clients to keep old payment settings. A current test explicitly expects a payment-description change to change the version, contrary to this implementation.

- [ ] Include all versioned response content in the fingerprint and refresh it whenever that content changes, or define separate versions for independently changing data.
- [ ] Keep deterministic ordering and verify both unchanged-data and changed-payment scenarios.

### F13. Role authorization differs from the earlier plan and tests

Sources: [User](app/Models/User.php), lines 43-46; [administrator middleware](app/Http/Middleware/EnsureAdministrator.php); [payment tests](tests/Feature/PaymentWorkflowTest.php), line 17.

`isAdministrator()` now allows active `Super Admin` and `Staff`, and excludes `Admin`. The earlier plan and payment test setup expect `Admin` access. If the previous policy is still intended, Staff gains financial administration rights while Admin loses them. If this was intentional, the contract and tests need to say so.

- [ ] Confirm the authorized roles for payment review and method management, then align checks and tests.
- [ ] Review the existing role foreign key's cascading user deletion against financial-history retention requirements; referenced financial records may block such deletion, so physical deletion behavior must be deliberate.

## Additional compatibility and design work

| Area | Remaining work |
| --- | --- |
| Wallet ownership and initialization | Enforce one wallet per user if intended; default balances to zero; standardize `avaliable_balance`; define how `version` participates in concurrency control. The foreign-key chain repeats `restrictOnUpdate()` and has no explicit delete action. |
| Wallet reconciliation | Define whether movement before/after balances describe available, locked, or total funds; preserve enough detail to reconstruct both buckets. |
| User transaction relationship | `User::transactions()` defaults to `user_id`, while current `transactions` uses `payment_user_id`; the inverse `Transaction::user()` already names the current key. |
| Saved payment accounts | Enforce ownership, merchant/customer usage, method/type consistency, and immutable account snapshots on accepted payments. Current account references alone permit historical details to change when the account changes. |
| References | Define retry and provider-reference uniqueness at the correct scope; neither six-digit suffixes nor unconstrained reference columns enforce duplicate prevention. |
| Result storage | Draw-based results can work; align or retire the existing `TwodResult`/`ThreedResult` models and factories if separate result tables are not part of the chosen design. |
| Test setup | `ModelSchemaTest`, `SupportTest`, and `MasterDataTest` remove `role_id` before inserting users, although the current role migration requires it. Keep valid role factories in test setup. |
| Existing payment/auth assertions | Payment tests still require absent routes and the old accounting schema; auth tests still expect `users.balance` and a wallet endpoint. Their presence does not establish passing coverage. |
| Business rules | Confirm calendar/timezone, limits, exposure caps, payout meaning/rounding, cancellation/refund policy, result correction, and the manual transfer completion stage. |

## Recommended order

1. Select and document the authoritative accounting design; establish the existing-data migration strategy.
2. Correct wallet history/retry constraints, withdrawal completeness, money precision, and rollback behavior.
3. Complete draw/bet relationships, accepted-rate snapshots, lifecycle, and 3D storage; align models/factories.
4. Align controllers, UUID routes, role policy, support fields, and master-data versioning with the selected contract.
5. Repair existing test setup and assertions, then run isolated migration/rollback/seeding checks and focused feature tests. Verify money concurrency on the intended database engine before relying on settlement or withdrawal behavior.

## Verification performed

- Inspected current Git status, migration inventory, models, relevant services/controllers/resources, factories/seeders, routes, and feature-test source.
- Compared the current source with the earlier checklist and saved plans. Older findings were rechecked against current files.
- Confirmed installed direct dependencies with the PHP 8.5 executable and Composer: Laravel 13.35.0, Sanctum 4.3.3, Scribe 5.11.0, Pest 5.3.1, and Pint 1.32.1.
- Checked decimal and schema-removal API behavior in the installed Laravel source.
- Attempted API route inspection; it failed with the missing-controller exception recorded in F10. PHP also emitted startup warnings for unavailable `opcache` and `pdo_firebird` extensions.

Not performed: migrations, rollback execution, seeding, automated tests, live API requests, connected-database inspection, concurrency checks, or a new video review. Predicted insert, precision, and rollback problems above are source-level findings unless explicitly identified as observed command output. No passing test or runtime financial guarantee is claimed.

Only this Markdown report was added. Application code, existing plans/checklist, dependencies, and database contents were not intentionally changed. No PHP files were edited, so Pint was not required.
