# Table review against the video-derived plan

Reviewed: 2026-10-17.

Your tables are a usable foundation, but they do not yet support the complete planned deposit, betting, settlement, withdrawal, and history flow.

This is a review checklist only. No application code, migrations, models, or existing plans were changed. Unchecked items mean they need review; they do not mean a fix has been implemented.

Compared with:

- [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md)
- [DATABASE_STRUCTURE.md](DATABASE_STRUCTURE.md)
- Current migrations, models, factories, routes, and payment service.

The saved plans describe an older working-tree snapshot. In particular, the current migrations use separate `wallet` and `wallet_transactions` tables, while the plan uses `users.balance` and `transactions` as the wallet ledger. A different design can work, but its rules and implementation must agree.

## Critical findings

### 1. Wallet history currently permits only one movement per wallet

Source: [payment and wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php), `wallet_transactions` definition.

`wallet_transactions.wallet_id` has a unique constraint. After one movement, a second movement for that wallet would violate this constraint. There is also no foreign key from this column to `wallet.id`.

- [ ] Allow many transaction rows per wallet and index `wallet_id` for history queries.
- [ ] Add the wallet foreign key.
- [ ] Define the scope of `idempotency_key` and enforce its uniqueness in the database.
- [ ] Ensure the same deposit, bet, payout, or refund cannot create a second financial effect on retry.

### 2. 3D numbers are limited to two characters

Sources: [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php), [3D entry factory](database/factories/ThreedEntriesFactory.php).

Both `threed_draws.winning_number` and `threed_bets.draw_number` use string length 2. The factory also generates only two digits. This does not correctly represent `007` or `550`; database enforcement depends on the engine.

- [ ] Use three-character strings for both 3D number columns.
- [ ] Validate exactly three ASCII digits and preserve leading zeroes.
- [ ] Update the factory and related checks to use three-digit examples.

### 3. Bets lack the information needed for purchases and settlement

Sources: [2D migration](database/migrations/2026_10_17_125733_create_twodbet_table.php), [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php).

Both bet tables contain a number, date/session, owner, and status, but no stake amount or draw foreign key. They also lack the accepted payout rate, payout amount, settlement timestamp, and retry key.

- [ ] Add `draw_id` linked to the matching game's draw table.
- [ ] Store a positive stake amount.
- [ ] Snapshot the payout multiplier accepted for the bet.
- [ ] Record payout amount and settlement time.
- [ ] Define request-key uniqueness, either per entry or through a shared bet slip.
- [ ] Link stake debits, winnings, and refunds back to the originating bet or slip.

Copied draw date/session values can be useful snapshots, but they do not replace a reliable draw relationship.

### 4. Withdrawal requests cannot fully describe a pending withdrawal

Source: [payment and wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php), `withdraw_requests` definition.

A request has no amount or destination account, while its `transaction_id` is nullable. Both `approve_user_id` and `declined_user_id` are required even when its status is only `requested`.

- [ ] Store the requested amount on the request or require a linked record that already contains it.
- [ ] Store the selected destination account and immutable account-name/number snapshots.
- [ ] Allow approver and decliner IDs to remain null until their respective actions occur.
- [ ] Define cancellation, rejection reasons, and review notes if following the saved plan.
- [ ] Define exactly when funds move from available to locked, when a completed transfer consumes locked funds, and when rejection/cancellation releases them.

## High-priority findings

### 5. Current payment code expects a different schema

Sources: [PaymentService](app/Service/PaymentService.php), [PaymentTransactions](app/Models/PaymentTransactions.php), [Transaction](app/Models/Transaction.php), [User](app/Models/User.php).

The service expects:

- A `payment_transactions` table, absent from the current create-table migrations.
- A `users.balance` column, also absent.
- Ledger columns such as `user_id`, `payment_transaction_id`, `balance_before`, and `balance_after` on `transactions`.
- An active `status` on payment methods and receiving-account details on the method.
- Payment types/states such as `withdrawal`, `rejected`, and `cancelled`.

The new schema instead places payment records in `transactions`, accounts in `payment_accounts`, and movements in `wallet_transactions`. It uses `withdraw` in several enums. `PaymentTransactions::paymentMethod()` also calls `withTrashed()`, but the current `PaymentMethod` model does not use soft deletion.

- [ ] Decide which accounting design is authoritative.
- [ ] Align the service, models, enums, relationships, and planning documents with that design.
- [ ] Ensure all money writers use the same balance source and locking rules.

The separate wallet design is possible. The existing payment service cannot use it unchanged.

### 6. Draws lack lifecycle and payout information

Sources: [2D draw definition](database/migrations/2026_10_17_125733_create_twodbet_table.php), [3D draw definition](database/migrations/2026_10_17_125726_create_threedbet_table.php).

Both draw tables lack lifecycle status and payout multiplier. The 2D table also lacks opening/cutoff times and requires a winning number before a result is available. Existing date indexes do not enforce unique draw identity.

- [ ] Support scheduled, open, closed, settled, and cancelled states, or an equivalent clearly defined lifecycle.
- [ ] Store acceptance windows and the expected draw time with a defined timezone policy.
- [ ] Allow the 2D winning number to remain null until publication.
- [ ] Store the payout multiplier and snapshot it on accepted bets.
- [ ] Enforce unique draw identity: date/session for 2D; confirm the intended 3D calendar before choosing its uniqueness rule.
- [ ] Record settlement and cancellation details needed for history.

Bet acceptance must check the server time and draw state inside the same transaction as the debit. A scheduler delay must not extend the cutoff.

### 7. Money columns have an implicit, limited precision

Source: [payment and wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php).

The migration uses bare `decimal()` for payment amounts, wallet balances, and movement amounts/balances. In the installed Laravel 13.35.0, this defaults to `decimal(8,2)`, allowing up to `999,999.99` where precision is enforced. This is smaller than the ranges described in the saved plan and payment code.

- [ ] Choose an explicit supported monetary range and precision.
- [ ] If retaining the plan's whole-MMK policy, use an appropriate integer representation consistently.
- [ ] Enforce positive payment/stake amounts and nonnegative available/locked balances.
- [ ] Use exact arithmetic with an agreed payout rounding policy.

### 8. Wallet ownership and balance initialization need constraints

Source: [payment and wallet migration](database/migrations/2026_10_17_121133_create_transaction_table.php), `wallet` definition.

`user_id` is not unique, so multiple wallets for one user are permitted. Available and locked balances have no defaults. The available balance column is spelled `avaliable_balance`.

- [ ] Enforce one wallet per user if that is the intended design.
- [ ] Initialize available and locked balances to zero.
- [ ] Standardize the available-balance column name before callers depend on it.
- [ ] Define how the UUID `version` is used if it is intended for optimistic concurrency control.
- [ ] Define whether ledger before/after balances describe available funds, locked funds, or total funds, and preserve enough information to reconcile both balances.

### 9. Rollbacks do not reverse table creation

Sources: [payment migration](database/migrations/2026_10_17_121133_create_transaction_table.php), [2D migration](database/migrations/2026_10_17_125733_create_twodbet_table.php), [3D migration](database/migrations/2026_10_17_125726_create_threedbet_table.php).

The payment migration creates six tables but drops only `transactions`. The remaining `withdraw_requests.transaction_id` foreign key can prevent that drop. The game migrations try to drop `twodbet` and `threedbet`, which are not the names they create.

- [ ] Reverse every created table in dependency-safe order.
- [ ] Drop game bets before their corresponding draws once draw foreign keys are added.
- [ ] Verify rollback and reapplication in an isolated database after implementation.

## Additional plan and compatibility gaps

### 10. Multi-number checkout needs a shared purchase identity

Reference: [atomic game receipts proposal](DATABASE_STRUCTURE.md#b-atomic-game-receipts--phase-5-and-phase-4-if-required-for-2d).

The recorded 3D entry list represents one confirmed purchase containing several numbers. Current bets have no shared receipt, total, or request key.

- [ ] Add a bet-slip header or equivalent purchase record linking its individual bets.
- [ ] Give the purchase an owner, draw, total, and retry key with a uniqueness rule.
- [ ] Accept all entries and debit the total in one database transaction.

### 11. Saved accounts need ownership rules and historical snapshots

Source: [payment accounts definition](database/migrations/2026_10_17_121133_create_transaction_table.php).

Your `payment_accounts` table can support the saved-account screen. It does not automatically require another account table.

- [ ] Distinguish merchant receiving accounts from customer withdrawal destinations through enforced ownership/usage rules.
- [ ] Verify the account belongs to the correct owner and matches the requested method/type.
- [ ] Prevent conflicting `type` values between an account and its payment method.
- [ ] Snapshot destination details on accepted requests so account edits do not rewrite historical payment details.
- [ ] Prevent accidental reuse of payment references and request keys with suitable uniqueness rules. A six-digit transaction suffix alone is not a reliable globally unique payment identity.

### 12. Choose one result-storage approach

Sources: [TwodResult](app/Models/TwodResult.php), [ThreedResult](app/Models/ThreedResult.php), current draw definitions.

Winning numbers can live directly on draws. Separate result tables are optional for that design, but the existing result models expect `twod_results` and `threed_results`, which the current migrations do not create.

- [ ] Choose direct draw results or separate result records and align the models.
- [ ] Record publication time and who recorded the result.
- [ ] Prevent silent result changes after settlement; define a correction policy if required.

### 13. Remaining code compatibility issues

These affect use of the schema even after tables are created.

- [ ] Replace numeric-only route constraints for UUID parameters. [Routes](routes/api.php)
- [ ] Align `User::transactions()` with `payment_user_id` if keeping the current `transactions` definition; it currently expects `user_id`. [User model](app/Models/User.php)
- [ ] Align `Support` mass-assignment fields with the current `type` and `value` columns. It currently lists the older `link` and `additional_informations` fields instead. [Support model](app/Models/Support.php)
- [ ] Review physical role deletion: `users.role_id` currently cascades deletion to users, which conflicts with preserving account/financial history. [Role relationship migration](database/migrations/2026_10_09_051034_add_role_id_column_to_user_table.php)
- [ ] Reconcile existing factories and tests with the selected schema. For example, the current model-schema test uses two-digit values for 3D and removes the required user role before insertion. [Model schema test](tests/Feature/ModelSchemaTest.php)

### 14. Business decisions still needed

- [ ] Confirm actual 2D/3D payout rates and whether they include the returned stake. The saved video review reports banner values of 85/550; those are not an approved settlement rule by themselves.
- [ ] Confirm business timezone, draw calendar, opening times, and cutoffs.
- [ ] Confirm stake limits and any per-number exposure limits.
- [ ] Confirm customer cancellation, whole-draw cancellation, refunds, and result-correction rules.
- [ ] Confirm the manual withdrawal transfer stage and what approval means.
- [ ] Add a phone profile field only if matching the video's home card is required; that screen does not by itself establish phone/OTP authentication requirements.

## Verification status

Completed: source-level comparison of current migrations, models, relevant factories/tests, routes, payment code, and the two saved planning documents. Confirmed installed PHP package versions and Laravel's local `decimal()` default.

Not performed: application code changes, migration execution, rollback execution, automated tests, live API requests, connected-database inspection, or a new review of the reference video. Video-related requirements above come from the saved implementation plan.

Resolve the critical accounting and betting gaps before relying on these tables for the complete customer flow. Table definitions alone cannot guarantee atomic debits, cutoff enforcement, or one-time settlement; those also require compatible service transactions, locking, and verification.
