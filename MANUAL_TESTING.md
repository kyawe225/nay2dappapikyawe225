# Manual testing and completion review

Reviewed: 2026-10-21. This describes the current API source, including existing uncommitted work.

## Is it finished?

The core API flow is implemented in source: authentication, accounts, payments, wallets, 2D/3D batch betting, queued result calculation, payout preview, payout confirmation, and session refunds. It needs the manual checks below against your running database before it can be called finished.

| Area | Current source | Remaining check |
| --- | --- | --- |
| Routes/services | All 58 API routes load | Send authenticated requests |
| Authentication/profile | Register, login, refresh, logout, profile, password change/reset | Token revocation and configured mail |
| Payments/wallet | Requests, reviews, available/locked balances, movement history | Apply migrations; reconcile actual balances |
| Betting | Atomic `entries` array, separate amount per number, retry handling | Both games, validation and insufficient funds |
| Odds settings | Separate 2D/3D settings; newest row applies until another is added | Same-day changes, both sessions and saved bet payouts |
| Results/payouts | Queued calculation; preview token required before payment | Worker, totals and exactly one credit |
| Cancellation | Whole date/session refund | Exactly one refund per entry |
| Cutoffs | Optional server enforcement, disabled by default as requested | Enable later when wanted |
| Administration | Accounts, roles, master data, dashboard, activity and history under `/api/admin/...` | Separate-role permissions |
| Customer screens | API accepts explicit entries from your client | Connect and check your frontend |

Odds are settings for the game type. They are not configured per draw, date, AM/PM session or number. Each new row replaces the current setting for new bets of that type. Existing bets retain their accepted odds and potential payout. There is no GST setting.

## 1. Local setup

Use PHP 8.5. The default shell PHP may be another version.

~~~powershell
$php85 = 'C:/laragon/bin/php/php8.5/php.exe'
& $php85 artisan migrate:status --no-interaction
& $php85 artisan migrate --no-interaction
& $php85 artisan db:seed --class=DatabaseSeeder --no-interaction
& $php85 artisan serve --host=127.0.0.1 --port=8000 --no-interaction
~~~

Run these on a development database. These are instructions, not commands executed during this review. Seeding creates sample accounts/history. Use a newly registered customer for the balances below: sample history does not fund a wallet or reserve money for old sample withdrawals.

Apply these completion migrations:

- `2026_10_21_024913_complete_payment_review_and_wallet_constraints.php`
- `2026_10_21_032647_complete_betting_results_and_account_management.php`
- `2026_10_21_085249_enforce_wallet_ledger_and_payment_references.php`
- `2026_10_21_094349_allow_multiple_odds_settings_per_day.php`

The payment-reference migration stops on duplicate payment references or wallet movements without a wallet. Reconcile the named data before retrying. Existing duplicate wallets/movement keys or draw sessions can also block earlier unique constraints. The new odds migration removes the unique start-date constraint from both odds tables and adds an index for newest-row lookup, allowing several settings on the same day. Older migrations still introduce the historical start-date constraint before this migration removes it. If an older database used previous base-migration definitions, compare its schema with the current files: editing an applied migration does not update that database.

Rolling back the new odds migration refuses to restore unique start dates if duplicate dates exist in either odds table. Resolve those rows deliberately on a database copy before retrying; the migration does not delete odds history.

Start the result worker in another terminal:

~~~powershell
$php85 = 'C:/laragon/bin/php/php8.5/php.exe'
& $php85 artisan queue:work --tries=3 --timeout=60 --no-interaction
~~~

Use `QUEUE_CONNECTION=database` with the jobs/failed-jobs tables for queue checks. With `sync`, calculation runs during the result request, so you may not observe a pending state. Restart workers after code changes.

### Postman setup

Create local variables:

| Variable | Value |
| --- | --- |
| `baseUrl` | `http://127.0.0.1:8000` |
| `customerToken` | Customer access token |
| `otherCustomerToken` | Another customer's access token |
| `adminToken` | Administrator access token |
| `refreshToken` | Customer refresh token |

Use URLs such as `{{baseUrl}}/api/twod/bets`. Set `Accept: application/json` and `Content-Type: application/json`. Protected calls need `Authorization: Bearer {{customerToken}}` or the appropriate administrator token.

All administrator management requests use `{{baseUrl}}/api/admin/...`, such as `/api/admin/twod/odds`, `/api/admin/threed/results` and `/api/admin/payment/deposit`. Customer requests use their existing `/api/...` paths. Both roles sign in through `/api/auth/login`.

Service successes return `{"status":200,"message":"...","data":...}`. Validation normally returns HTTP `422` with `errors`. Authentication/authorization/missing resources/conflicts use `401`/`403`/`404`/`409`; insufficient balance uses `400`. Check the actual HTTP status too.

## 2. Authentication and profile

1. Send `POST /api/auth/register`:

   ~~~json
   {"name":"Manual Customer","email":"manual.customer@example.test","password":"ManualPassword123!"}
   ~~~

2. Expect `200` and the Customer role. Registration returns no tokens. Supplying a role must not elevate registration.
3. Send `POST /api/auth/login` with that email/password. Save `data.access_token` and `data.refresh_token`.
4. Sign in as a second customer and a Super Admin. A newly seeded local Super Admin is `test@example.com` / `password`; an existing account retains its password.
5. Check `GET /api/profile` and `GET /api/wallet`. Expect the new customer's profile and zero balances. The balance field is spelled `avaliableBalance`.
6. Try a wrong password, missing token, and a refresh token on `/api/wallet`. Expect rejection. Repeated attempts may hit `429` throttling.
7. Call `POST /api/auth/refresh` with the refresh token as Bearer token. Save the new pair; check the old pair stops working.
8. Call `POST /api/auth/logout` using the current access token. Both tokens for that session must stop working. Sign in again.

## 3. Payment accounts

1. As customer, call `GET /api/master` and `GET /api/paymentmethod/deposit`.
2. Pick an active deposit method and its administrator receiving account; save both UUIDs. Deposit and withdrawal methods are separate records even if their provider names match.
3. If needed, create an administrator receiving account using `POST /api/admin/paymentaccount/deposit`:

   ~~~json
   {"userName":"Manual Admin","phoneNumber":"09911112222","paymentMethodId":"<deposit-method-uuid>","descriptions":"Manual testing"}
   ~~~

4. Obtain a withdrawal method from `data.withdrawal_payment_methods` in `GET /api/master`.
5. As customer, create a destination using `POST /api/paymentaccount/withdrawal`:

   ~~~json
   {"userName":"Manual Customer","phoneNumber":"09933334444","paymentMethodId":"<withdrawal-method-uuid>","descriptions":"Manual testing"}
   ~~~

6. Save its UUID. Check `GET /api/paymentaccount/withdrawal` and `GET /api/paymentmethod/withdrawal`; these return the current customer's accounts.
7. Duplicate creation must return `409`. Another customer's destination must not be usable during a withdrawal.
8. Administrator account status uses `PATCH /api/admin/paymentaccount/deposit/{account}/status` with `{"status":"inactive"}` or `{"status":"active"}`. Inactive accounts must not accept new deposits.

## 4. Deposit and fund the customer

As customer, send `POST /api/payment/deposit`. The capitalized ID field names are intentional:

~~~json
{
  "amount": "10000.00",
  "lastSixTranId": "123456",
  "PaymentAccountId": "<admin-deposit-account-uuid>",
  "PaymentMethodId": "<deposit-method-uuid>",
  "clientReference": "d744a9ec-c457-4ab0-820c-e2e0b5cefe35"
}
~~~

1. Expect a pending deposit, no credit, and save `data.id`.
2. Repeat the request: same deposit ID, no additional record.
3. Keep the reference and change amount/account: expect `409`.
4. As admin, inspect `GET /api/admin/payment/deposit?status=pending`.
5. Approve using `PATCH /api/admin/payment/deposit/{transaction}/status` with `{"status":"approved"}`.
6. Check customer `/api/wallet`, `/api/wallet/transactions` and `/api/payment/deposit/history`. Expect available `10000.00`, locked `0.00`, one `deposit` movement.
7. Repeat approval: balance stays `10000.00`.
8. Separately create another pending deposit and reject with `{"status":"rejected"}`. No credit. Changing a completed approval to rejected must return `409`.

This is request/review accounting; the API does not perform real KBZPay/WavePay transfers.

## 5. Configure 2D and 3D odds settings

As admin, send `POST /api/admin/twod/odds`:

~~~json
{"name":"Manual 2D odds","oddNumber":"85","payoutMode":"total"}
~~~

Then `POST /api/admin/threed/odds`:

~~~json
{"name":"Manual 3D odds","oddNumber":"550","payoutMode":"total"}
~~~

Each POST creates a new row and takes effect immediately for new bets of that type. Save the returned `data.id`. No draw, number, session or start date is required to configure odds. `name`, `oddNumber` and `payoutMode` are required. `startDate` remains an optional `Y-m-d` metadata field for existing clients and defaults to today's date; it does not schedule activation or expiry.

Check the following before continuing:

1. As customer, call `GET /api/twod/odds` and `GET /api/threed/odds`. Expect the IDs just created and rates `"85.0000"` / `"550.0000"`.
2. Add `?drawDate=2026-10-22&timeType=am`, then a different date and `timeType=pm`. Each type still returns its current setting. Those query fields do not select odds.
3. Repeat the 2D POST with the same rate and a different name on the same day. Expect `200` and a new ID. Both rows remain in `GET /api/admin/twod/odds`; the newest row appears first and is returned by the customer endpoint. The 3D setting stays unchanged.
4. Repeat for 3D at `550`. Changing 3D must not change 2D. Keep these rates for the example balances below.
5. Try `PATCH /api/admin/{game}/odds/{odd}` with a valid body. Expect `409`, including when no bets have used the row. Change a setting by adding a new row through POST. A nonexistent valid UUID returns `404`.
6. As customer, try GET/POST/PATCH on administrator odds endpoints. Expect `403`; without a token, expect `401`.

The newest row is selected by `created_at`, then UUID `id` to resolve equal timestamps. Several rows may share the same `startDate`. A supplied future or past start date does not delay or change the new row's effect.

`total` credits stake multiplied by odds. `profit` credits that amount plus the stake. Stake `100.00` at `85` pays `8500.00` or `8600.00` respectively.

Missing odds or a null payout mode on the newest row blocks betting with `409`. The service does not fall back to an older row or the other type's setting. Missing odds returns `404` from the customer odds endpoint. Standalone odds seeders leave the mode unset; configure it explicitly.

Run the different-rate checks in section 13 after the main balance sequence, using a separate funded customer and unused sessions. Adding new settings must not change old bets' saved odds/potential payouts.

## 6. Send the 2D number array

Use an unused date/session. Send `POST /api/twod/bets` as the funded customer:

~~~json
{
  "drawDate": "2026-10-22",
  "timeType": "am",
  "clientReference": "ec95f8fb-cc01-4b26-ae26-5e7ca64bc098",
  "entries": [
    {"number": "05", "stakeAmount": "100.00"},
    {"number": "12", "stakeAmount": "200.00"}
  ]
}
~~~

Expected:

- `200`; `data.batchId` is a UUID, `data.totalStake` is `"300.00"` and `data.clientReference` matches.
- Two entries share the batch ID, with entry indexes `0` and `1`.
- `"05"` retains its zero. Initial status/resultStatus are `active`/`pending`.
- Accepted odds `"85.0000"`; potential payouts `"8500.00"` and `"17000.00"`.
- Wallet available `9700.00`, locked `0.00`.
- One `bet_placed` movement debits the batch total `300.00`.
- No draw/result row is required before purchase.

Numbers are fixed-width strings. Send 1 to 1000 explicit entries with positive stakes, at most two decimal places and at most 50 digits before the decimal point. The server calculates totals/odds/payouts. Duplicate numbers are separate charged wagers. Reverse/permutation expansion belongs in your client.

### Retry checks

1. Repeat the exact body: same batch, no extra rows/debit.
2. Changing amount `100.00` to `100` remains an identical normalized retry.
3. Same reference with changed number/amount/date/session/entry order: `409`.
4. A new reference means a new purchase and another debit if accepted.
5. References are scoped to customer and game. Generate a new UUID for each intended purchase.
6. After a result/cancellation, the identical original retry still returns its batch without debit. A new purchase for that closed session fails.

### Negative checks

Use a fresh reference each time. No failed request may create partial bets/debits.

| Input | Expected |
| --- | --- |
| Empty/missing entries or over 1000 entries | `422` |
| Arbitrary object keys instead of an ordered entries array | `422` |
| Scalar entry, missing fields, or extra fields inside an entry | `422` |
| Number `"5"`, `"005"`, numeric `5` or nondigits for 2D | `422` |
| Zero/negative stake, exponent notation, more than two decimals | `422` |
| Missing/invalid reference UUID | `422` |
| One valid entry and one invalid entry | Whole batch rejected, `422` |
| Combined stake exceeding available balance | `400`, no partial purchase |
| Administrator placing a bet | `403` |
| Customer on administrator result/odds endpoints | `403` |
| Unknown game, e.g. `/api/fourd/bets` | `404` |

Check `GET /api/twod/bets` and `GET /api/twod/bets/{bet}`. Another customer must get `404` for this customer's bet ID. Supplying `paymentUserId` must not expose another customer's records.

## 7. Calculate, preview and pay

1. Stop the worker briefly to observe pending calculation. As admin, send `POST /api/admin/twod/results`:

   ~~~json
   {"drawDate":"2026-10-22","timeType":"am","winningNumber":"05","name":"Manual 2D result"}
   ~~~

2. Before calculation completes, preview/settle must return `409`. New purchases for that session must fail.
3. Start the worker. Poll `GET /api/admin/twod/results?drawDate=2026-10-22&timeType=am` until `calculationStatus` is `completed`.
4. Check admin bets for that session: `"05"` won, `"12"` lost. Wallet remains `9700.00`; calculation does not pay.
5. Send `POST /api/admin/twod/results/preview`:

   ~~~json
   {"drawDate":"2026-10-22","timeType":"am"}
   ~~~

6. Expect two bets, one winner, one loser, total stake `"300.00"`, payout `"8500.00"` and a 64-character `data.previewToken`. Preview does not change balances.
7. Send `POST /api/admin/twod/results/settle`:

   ~~~json
   {"drawDate":"2026-10-22","timeType":"am","previewToken":"<token-returned-by-preview>"}
   ~~~

8. Expect available `18200.00`, one `bet_won` movement for `8500.00`, winner status `settled`, loser status `lost`, and settlement timestamps.
9. Repeat settlement: no second credit. Before settlement, missing preview token returns `422` and wrong token returns `409`.
10. A changed winning number must return `409`. Cancelling a paid result must return `409`.
11. Customer `GET /api/twod/results?drawDate=2026-10-22&timeType=am` shows `"05"`.
12. Other dates/sessions/games must not be calculated/paid by this result.

Before payment, a calculated bet can still have status `active` with resultStatus `won`/`lost`.

## 8. Repeat for 3D

Send `POST /api/threed/bets`:

~~~json
{
  "drawDate": "2026-10-22",
  "timeType": "pm",
  "clientReference": "f2176103-78ad-411f-aa1d-ef227a335abd",
  "entries": [
    {"number": "007", "stakeAmount": "100.00"},
    {"number": "123", "stakeAmount": "200.00"}
  ]
}
~~~

Repeat result/preview/settle with `/api/admin/threed/...`, PM and winning number `"007"`. Continuing the clean scenario: available becomes `17900.00` after purchase, payout is `55000.00`, final available is `72900.00`. Check `007`/`000` retain three digits; `"07"`, `"0007"` and numeric `7` fail.

## 9. Withdrawal approval and decline

As customer, send `POST /api/payment/withdrawal`:

~~~json
{
  "amount": "1000.00",
  "paymentAccountId": "<customer-withdrawal-account-uuid>",
  "paymentMethodId": "<withdrawal-method-uuid>",
  "clientReference": "b2cb2620-a4d1-4ed5-a8c0-967485882bf8"
}
~~~

1. Save request ID. Expect status `requested`, available `71900.00`, locked `1000.00`.
2. Repeat: no second reservation. Change amount under same reference: `409`.
3. As admin, inspect `GET /api/admin/payment/withdrawal?status=requested`.
4. Call `PATCH /api/admin/payment/withdrawal/{withdrawal}/status`:

   ~~~json
   {"status":"approved","lastSixTranId":"654321"}
   ~~~

5. Available remains `71900.00`; locked becomes `0.00`. One approved transaction is linked through `transaction_id`. Approval must not debit again.
6. Repeat approval: no extra transaction/debit.
7. Request `500.00` under a new reference: available `71400.00`, locked `500.00`.
8. Decline using `{"status":"declined"}`: available `71900.00`, locked `0.00`, one `withdraw_rejected` movement.
9. Repeat decline: no second refund. Approving a declined request returns `409`.
10. Insufficient balance returns `400`; someone else's destination must fail without reservation.
11. Inspect `/api/payment/withdrawal/history`, `/api/payment/withdrawal/request/history` and wallet history.

Old seeded requested withdrawals have no real wallet reservation and may intentionally return `409` on review. Use a newly submitted request.

## 10. Cancel an unpaid session

1. On a separate session, e.g. `2026-10-23` 2D PM, buy stakes `100.00` and `200.00` under a new reference. Continuing the sequence, available becomes `71600.00`.
2. As admin, send `POST /api/admin/twod/results/cancel`:

   ~~~json
   {"drawDate":"2026-10-23","timeType":"pm","reason":"Manual cancellation check"}
   ~~~

3. Both bets become `cancelled` with zero settle amounts. One `bet_refunded` movement per entry restores available to `71900.00`.
4. Repeat cancellation: no second refund. A new purchase for that session returns `409`.
5. A job queued before cancellation must not pay/reactivate cancelled bets when it runs.

Customers have no cancellation/refund endpoint; administrators cancel the whole date/session.

## 11. Time restrictions: unrestricted now, optional later

The source defaults are shown below. Check your environment and cached configuration before testing:

~~~dotenv
BETTING_ENFORCE_CUTOFFS=false
BETTING_TIMEZONE=Asia/Yangon
~~~

With enforcement off, both AM/PM are accepted for either game, including dates after their clock cutoff, if odds exist and no result/cancellation has closed the session. This follows the request to allow entries for now.

Later set `BETTING_ENFORCE_CUTOFFS=true` and clear/rebuild cached configuration:

~~~powershell
$php85 = 'C:/laragon/bin/php/php8.5/php.exe'
& $php85 artisan config:clear --no-interaction
~~~

| Game | Session | Optional cutoff in Asia/Yangon |
| --- | --- | --- |
| 2D | AM | 11:55 |
| 2D | PM | 15:55 |
| 3D | PM only | 14:00 |

When enabled, a new batch at/after cutoff returns `409`; 3D AM returns `409`. An identical accepted retry still returns its original batch. Check before/at/after cutoff in development.

Future dates remain bookable until their cutoff. Holiday calendars, allowed draw-date lists, opening windows and number exposure limits are not implemented. Basic number/amount/wallet validation remains enforced.

## 12. Administration and other account checks

All administrator routes below and all odds/result administration routes must start with `/api/admin/`. The source already groups them under authentication, active-account, access-token and administrator middleware. Verify the paths and authorization on your running application:

1. Without a token, `GET /api/admin/dashboard` returns `401`.
2. With a customer token, dashboard, odds history, result writes and payment reviews return `403`.
3. With an administrator token, use the `/api/admin/...` paths shown below. Account management and role listing require Super Admin.
4. `GET /api/dashboard` returns `404`. `POST /api/twod/odds` and `POST /api/twod/results` return `405` because only customer GET routes exist there. They must not create settings/results.

| Action | Endpoint/body | Expected |
| --- | --- | --- |
| Profile | `PATCH /api/profile`, name | Own profile updated |
| Email | `PATCH /api/profile`, email + currentPassword | Password required; changed email revokes sessions |
| Password | `POST /api/profile/password`, currentPassword/password/password_confirmation | Old sessions revoked |
| Forgot password | `POST /api/auth/forgot-password`, email | Generic response; token delivered through configured mailer |
| Reset password | `POST /api/auth/reset-password`, email/token/password/password_confirmation | Token valid once; sessions revoked |
| Accounts | `GET/POST /api/admin/accounts`; `GET/PATCH /api/admin/accounts/{account}` | Super Admin only |
| Roles | `GET /api/admin/roles` | Active roles available |
| Suspension | Account PATCH with `{"status":"suspended"}` | Sessions revoked; login/access denied |
| Dashboard | `GET /api/admin/dashboard` | Totals match manual records |
| Customer history | `GET /api/admin/accounts/{account}/history` | Correct payments/withdrawals/movements |
| Activity | `GET /api/admin/activities` | Account/master/odds/result operations recorded |
| Master CRUD | `/api/admin/master/{resource}` and `/{record}` | Admin authorization and in-use deletion protection |
| Version | `GET /api/master?version=<returned-version>` | Same version gives data null; changes change version |
| Support | `GET /api/support` | Active configured contacts |
| Destination removal | `DELETE /api/paymentaccount/withdrawal/{account}` | Owner only; old history retained |

Master resources: `payment-methods`, `support-types`, `supports`. Create examples:

~~~json
{"name":"Manual Provider","type":"deposit","description":"Manual testing","status":"active"}
~~~

~~~json
{"name":"Manual Help","imageLocation":"/images/support/1.jpg"}
~~~

~~~json
{"name":"Help website","supportTypeId":"<support-type-uuid>","type":"link","value":"https://example.com/help","status":"active"}
~~~

Account creation requires name/email/password, with optional roleId/status. Updates accept name/email/roleId/status, and prohibit password. Own role/status changes are blocked; the last active Super Admin is protected.

Administrator roles currently include Super Admin, Admin and Staff. Branch Manager has no administrator privileges. Branch ownership/commissions are not implemented.

Game history supports drawDate/timeType/status/resultStatus/page/perPage where relevant. Admin bet lists support paymentUserId; customer scope must remain their own. Payment admin lists support customer/status/date filters. Page size is 1 to 100. Customer payment history currently returns full arrays.

Odds are the exception to draw/session filtering: customer reads return the current type setting; administrator reads return the paginated settings history.

Payment reviews store reviewer IDs/timestamps on their records, but do not add a separate admin_activities entry. Payments link to retained account records; there are no immutable name/phone snapshots per payment.

## 13. Recovery and simultaneous requests

Use separate customers/sessions so these do not disturb the example balances:

- For each game, place a `100.00` bet at the configured rate and save its ID, request body, accepted odds and potential payout. Add a new odds row, e.g. `90` for 2D or `600` for 3D with `payoutMode: total`. Use a new reference to place bets for the same open session and another date/session: all new bets use the new rate. The old bet and an identical retry retain their original odds/payout and do not debit again.
- Record the matching result, calculate, preview and settle. Expect the old bet's original potential payout and the newer bet's new payout. Repeating settlement must not credit again. Changes in one type must not affect the other.
- Add another setting on the same day using the same `startDate`. It must succeed and become current immediately. Separately supply `startDate: 2099-01-01`; the row must still become current immediately. Restore the desired rates by adding new rows when these checks finish.
- Submit two identical batches at once: one purchase/debit.
- Submit different batches that cannot both fit the balance: no overspend/partial batch.
- Race a purchase against result/cancellation: an accepted-before-close bet is included; after closure it fails.
- Submit concurrent payout confirmations: one credit per winner.
- Submit concurrent payment approvals: one balance effect/approved transaction.
- Stop/start the worker around result recording: calculation resumes without automatically paying.
- Inspect `php artisan queue:failed`. After resolving a real failure, call `POST /api/admin/{game}/results/recalculate` with drawDate/timeType, then preview/settle. Do not change the winning number to recover a job.
- On a disposable database copy, check migration rollback/reapplication. Rollback was not run in this review.

Check concurrency on your intended MySQL/MariaDB database; SQLite does not establish equivalent row-lock behavior.

## 14. Verification record

Completed in the earlier source review (retained as historical evidence):

- Composer versions inspected: Laravel 13.35.0, Sanctum 4.3.3, Scribe 5.11.0, Pint 1.32.1.
- API route inspection succeeded: 58 routes.
- PHP syntax checks passed for 155 app/database/routes/config files.
- Required Pint formatting completed; it also formatted existing dirty PHP files.
- `git diff --check` passed.
- Effective betting config confirmed: enforcement off, timezone Asia/Yangon.
- BCMath and database PHP extensions are installed.

Completed for this odds/settings update:

- Composer versions rechecked: Laravel 13.35.0, Sanctum 4.3.3, Scribe 5.11.0, Pint 1.32.1 and Pest 5.3.1.
- API route inspection succeeded: 58 routes; all administrator controllers use `/api/admin/...`.
- `php artisan test --compact tests/Feature/BetWorkflowTest.php tests/Feature/AdminManagementTest.php`: 16 passed, 261 assertions, exit code 0, using SQLite `:memory:`. Checks cover latest-row odds for both types, multiple additions on the same day, date/session independence, old bet payouts/retries, separate settings, immutable history, and administrator route prefix/middleware, alongside the existing betting workflow.
- Required `vendor/bin/pint --dirty --format agent` formatting completed. The focused tests passed again afterward, and `git diff --check` passed.

The new migration was applied only inside the isolated tests. The running database was not migrated or seeded. Manual HTTP scenarios, mail delivery and concurrency checks were not executed. SQLite tests do not establish MySQL/MariaDB row-lock behavior. PHP emits startup warnings for unavailable opcache/pdo_firebird extension paths; the focused tests still passed.

Run the complete suite with `php artisan test --compact` before final sign-off.

Fill in after testing:

| Check | Result | Evidence: request/record ID, balance or issue |
| --- | --- | --- |
| Migrations/seed setup | Not run | |
| Authentication/profile/recovery | Not run | |
| Deposit approval/rejection/retry | Not run | |
| Latest 2D/3D odds, same-day changes and saved payouts | Manual check not run; focused tests passed | |
| 2D array validation/purchase/retry | Not run | |
| 2D calculation/preview/payment | Not run | |
| 3D leading zeros and payment | Not run | |
| Withdrawal approval/decline/retry | Not run | |
| Session cancellation/refund | Not run | |
| Ownership/roles | Not run | |
| Administrator `/api/admin/...` paths and customer rejection | Manual check not run; route structure test passed | |
| Master/support/account administration | Not run | |
| Queue recovery | Not run | |
| Concurrent target-database requests | Not run | |
| Optional cutoffs | Not run | |

Final sign-off depends on passing the required scenarios on your running environment.
