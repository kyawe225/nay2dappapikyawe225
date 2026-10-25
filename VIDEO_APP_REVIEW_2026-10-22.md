# App review against m.mp4

Reviewed: 2026-10-22. Scope: the current Laravel checkout, including existing uncommitted changes.

## Review result

**The app does not yet fully match the video.** Much of the backend is now implemented, but this checkout does not contain the customer interface shown in the recording. Session availability, number-entry helpers, initial odds setup, and some integration details still need work.

This is a source and video comparison, not a working-app sign-off. No application code was changed. Tests, database migrations, seeders, live HTTP requests, and target-database concurrency checks were not run.

If the mobile/Angular frontend is maintained in another repository, the UI items below need to be checked there. Its absence here does not establish that it is missing from your whole system.

## Video evidence

The actual file reviewed was [m.mp4](m.mp4), 56,620,069 bytes, 864 x 1920, duration **02:22.756**. I inspected 29 frames at five-second intervals and six additional frames at 00:07, 00:08, 00:57, 01:10, 01:12, and 02:17. The file contains audio, but audio was not transcribed; the findings refer to visible behavior.

| Video time | Visible requirement | Current source assessment |
| --- | --- | --- |
| 00:00; 00:55 | Customer home, name/phone, hidden balance, refresh, payment/history/help shortcuts, banner and game navigation | Profile, wallet and support APIs exist. Customer home is absent here; user profile has no phone field. |
| 00:07-00:08 | Deposit amount, presets 5,000/10,000/50,000/100,000, KBZ Pay/Wave Pay, receiving account, last-six-character transaction code | Backend broadly supports this; the form and provider switching still need UI integration. |
| 00:15-00:43; 00:50 | Deposit/withdrawal history tabs, loading and empty states | History APIs exist; customer screens are absent here. |
| 00:45 | Saved withdrawal-account selection, add-account action and empty state | Saved-account list/create/delete and withdrawal submission exist in current source. |
| 00:57 | 2D AM/PM choices with 11:55 AM and 03:55 PM cutoffs and unavailable states | Cutoff config exists, but enforcement defaults off and no customer session-availability response exists. |
| 01:00-02:10 | 3D number/amount inputs, keypad, 0/00/000, R/delete/ENTER, cart/total, date and 02:00 PM cutoff | Explicit-entry batch purchase exists; keypad/cart interface is absent here. |
| 01:10 | Number-generation helper with amount and a toggle | No helper implementation found; exact expansion rules need a decision. |
| 01:12 | Multiline paste/import screen and validation/continue action | No text parser/preview implementation found. |
| 02:15; 02:17; 02:19 | Dated 2D AM/PM results and 3D results; values include 00, 05 and 701 | Results APIs and string number storage exist; date grouping/order and UI integration need attention. |
| 02:20 | Customer betting-history tabs | Own-bet list/detail APIs exist; history interface is absent here. |

The video starts signed in. It does not demonstrate a successful deposit, completed withdrawal, accepted bet, administrator review or paid winning bet. Those flows require separate verification. The full 2D entry screen is not demonstrated, so its exact keypad behavior cannot be inferred from the 3D screen.

## Backend work already present

These items are implemented in current source; they should not be treated as missing merely because older reports say so. Runtime correctness remains unverified in this review.

| Area | Current implementation and evidence |
| --- | --- |
| Authentication and account management | Register/login/refresh/logout, profile/password/recovery, active-account middleware and administrator authorization. [Routes](routes/api.php), [AuthService](app/Service/AuthService.php), [UserService](app/Service/UserService.php). |
| Deposit providers and accounts | Active providers grouped with active administrator receiving accounts. [PaymentMethodService](app/Service/PaymentMethodService.php). |
| Saved withdrawal accounts | Customer-owned create/list/delete, destination validation and ownership checks. [PaymentAccountService](app/Service/PaymentAccountService.php), [WithdrawalAccountRequest](app/Http/Requests/WithdrawalAccountRequest.php). |
| Payment review | Pending deposit requests, administrator approval/rejection, withdrawal fund reservation and approval/rejection. [PaymentService](app/Service/PaymentService.php). |
| Wallet and ledger | Available/locked balances, transaction history, idempotency keys, one wallet per user and ledger foreign key in migrations. [WalletService](app/Service/WalletService.php), [wallet constraints](database/migrations/2026_10_21_024913_complete_payment_review_and_wallet_constraints.php), [ledger references](database/migrations/2026_10_21_085249_enforce_wallet_ledger_and_payment_references.php). |
| 2D/3D purchase | Explicit entries, digit-string validation, transaction around the whole batch, shared batch ID, server totals, saved accepted odds/potential payout, client-reference retry comparison and one batch debit. [BetRequest](app/Http/Requests/Web/BetRequest.php), [BetService](app/Service/BetService.php). |
| Results and winnings | Result recording, queued calculation, payout preview/confirmation, retry handling and session cancellation/refunds. [BetService](app/Service/BetService.php), [CalculateBetResults](app/Jobs/CalculateBetResults.php). |
| Administration and support | Payment review queues, odds settings, results, dashboard, account administration, master data and support endpoints. [Routes](routes/api.php). |
| UUID routing | Current account/payment/bet identifier routes use UUID constraints; the route-list command succeeds. [Routes](routes/api.php). |

The older [implementation plan](IMPLEMENTATION_PLAN.md) and [table review](TABLE_REVIEW_REPORT_2026-10-17.md) describe earlier source states. For example, saved withdrawal accounts and batch betting are now present, and the old missing-controller route-list failure did not occur in this review.

## Required work for matching the video

### 1. Customer screens and navigation — missing in this checkout

Evidence: [web routes](routes/web.php) serve the Laravel welcome page. [app.js](resources/js/app.js) is frontend scaffolding; the resources directory contains the welcome and generated API-documentation views, not the recorded customer app. [package.json](package.json) contains Laravel/Vite tooling and no Angular application.

- [ ] Build or locate the separate customer frontend and connect it to these APIs.
- [ ] Implement home, balance hide/show and refresh, deposit, withdrawal-account selection/addition, payment history, betting, results, betting history, and help navigation.
- [ ] Include the recorded loading/empty states, visible validation errors, submission feedback and prevention of accidental repeated taps.
- [ ] Add the banner/branding assets. A static banner is sufficient unless administrator-managed campaigns are required.

Acceptance: a customer can navigate the recorded screens using real API responses, including empty and error states.

### 2. Session availability and cutoff behavior — partial

Evidence: [betting config](config/betting.php) defines 2D 11:55/15:55 and 3D PM 14:00 in Asia/Yangon, but defaults `enforce_cutoffs` to false. [BetService](app/Service/BetService.php) checks those times only when enforcement is enabled. With enforcement off, valid odds and no closing result/cancellation, the service accepts past-date purchases and 3D AM as well as PM. The current [routes](routes/api.php) have no customer schedule/availability endpoint, and [DrawResource](app/Http/Resources/DrawResource.php) exposes neither opening/cutoff time nor an availability flag.

The existing [manual guide](MANUAL_TESTING.md) explicitly describes unrestricted betting as the current behavior. This is a difference from the reference, not a recommendation to change your chosen policy without review. Effective live configuration was not inspected.

- [ ] Decide whether to keep the unrestricted development policy or enforce the reference cutoffs.
- [ ] Define valid game dates/sessions and whether opening windows/holidays are needed; do not infer the full calendar from sample result dates.
- [ ] Expose server time/timezone, draw date, session, cutoff and availability/reason so the frontend can render the selector consistently.
- [ ] When restrictions are required, enforce them on the server as well as in the UI.

Acceptance: the selector and purchase API agree before/at/after cutoff and after result/cancellation; unavailable sessions cannot be purchased under the chosen policy.

### 3. Finish the draw-times/schema work — incomplete source

Evidence: [the latest draw-times migration](database/migrations/2026_10_21_095322_move_draw_odds_to_settings_and_add_draw_times.php) contains empty callbacks against `settings_and_add_draw_times`. It adds/removes no columns and does not implement its filename's intended changes. This is an empty scaffold, not proof that migration execution fails.

Both [TwodDraw](app/Models/TwodDraw.php) and [ThreedDraw](app/Models/ThreedDraw.php) list `open_time`/`cutoff_time` as fillable. The existing migrations supply these fields only for 3D. [DrawScheduleRequest](app/Http/Requests/Admin/DrawScheduleRequest.php) exists but is not wired to a route/controller/service. Result submission explicitly prohibits schedule fields in [DrawRequest](app/Http/Requests/Admin/DrawRequest.php). Legacy `draws.odds` columns also remain while betting uses the separate odds settings.

- [ ] Choose global configured times or editable per-draw times as the authoritative schedule.
- [ ] Complete the migration/model/API alignment for that choice; wire schedule management only if editable schedules are required.
- [ ] Resolve the unused scaffold and legacy odds columns with a migration strategy that preserves existing data.

Acceptance: a clean schema and an upgraded database both match the selected design; schedule fields are actually stored/read/enforced where intended.

### 4. Provide usable initial odds — setup gap

Evidence: [DatabaseSeeder](database/seeders/DatabaseSeeder.php) and [MasterDataSeeder](database/seeders/MasterDataSeeder.php) do not call the two odds seeders. Separately running [TwodOddSeeder](database/seeders/TwodOddSeeder.php) or [ThreedOddSeeder](database/seeders/ThreedOddSeeder.php) creates rates 85/550 with `payout_mode = null`. [BetService](app/Service/BetService.php) rejects a purchase with HTTP 409 if the latest rate has no valid `total`/`profit` payout mode. The recording's banner does not establish which payout mode is intended.

- [ ] Confirm whether 85/550 includes the returned stake (`total`) or represents profit plus stake (`profit`).
- [ ] Provide a documented setup step or approved seeding flow that creates valid settings for both games.
- [ ] Fetch displayed rates from `/api/twod/odds` and `/api/threed/odds`; keep the server's saved bet payout authoritative.

Acceptance: after normal setup, a funded customer can place each game without an unexpected missing-settings response, and banner/displayed rates agree with accepted odds.

### 5. 3D keypad, cart, generation and text import — missing interface/helpers

Evidence: [BetRequest](app/Http/Requests/Web/BetRequest.php) accepts an explicit list of 1-1,000 `{number, stakeAmount}` entries. There is no multiline parser, generator or matching UI. The batch purchase service already exists and should be reused.

- [ ] Implement keypad entry, backspace/clear, 0/00/000, ENTER, cart rows and total.
- [ ] Agree the exact meaning of R, the generation toggle and the text syntax shown at 01:12, including repeated digits, duplicate entries and amount assignment.
- [ ] Parse/expand into an explicit preview, highlight invalid lines and show the final stake total before checkout.
- [ ] Keep leading zeros as strings, e.g. `007`; submit one batch with a stable `clientReference` and reuse it for retries.
- [ ] Apply the server's batch-size and amount limits to the expanded preview. Preserve server validation and total calculation.

These helpers can live in the frontend; a new backend parsing endpoint is only needed if a shared authoritative parser is desired.

Acceptance: agreed examples produce the expected entries/amounts, invalid imports are explained, and a repeated submission does not create another debit.

### 6. Home identity and payment forms — integration work

Evidence: [User](app/Models/User.php), [RegisterRequest](app/Http/Requests/RegisterRequest.php), [ProfileRequest](app/Http/Requests/ProfileRequest.php) and [UserResource](app/Http/Resources/UserResource.php) support name/email, not a profile phone. Phone numbers on payment accounts are payment destinations and do not establish the customer's profile phone.

- [ ] If the home screen must show a customer phone, add an agreed profile-phone field and validation/API support. The video does not prove that phone login or OTP is required.
- [ ] Implement deposit presets and provider/account switching using `/api/paymentmethod/deposit`.
- [ ] Submit deposit fields exactly as currently named: `PaymentAccountId`, `PaymentMethodId`, `lastSixTranId`, `amount`, and a stable `clientReference`.
- [ ] Submit withdrawal fields as `paymentAccountId`, `paymentMethodId`, `amount`, and a stable `clientReference`.

[DepositRequest](app/Http/Requests/DepositRequest.php) requires a six-character code, matching the visible last-six-code form. Payment `clientReference` is optional in the backend; [PaymentService](app/Service/PaymentService.php) generates a new reference when it is omitted. The frontend should always supply/reuse one so a timeout retry cannot create another request/reservation.

Acceptance: changing providers selects a matching account, validation messages reach the form, and retrying the same operation returns the original request.

### 7. Results and history presentation — partial

Evidence: result APIs return individual draw/session records. [BetService::Paginate](app/Service/BetService.php) orders them by creation time, not draw date. A historical result inserted later can therefore appear above a newer draw, and pagination can separate AM and PM for one date.

- [ ] Render 2D results as date rows with distinct AM/PM values and 3D results as date/number rows, retaining `00`, `05` and `007`.
- [ ] Agree draw-date ordering and complete daily grouping across page boundaries; handle one missing session explicitly.
- [ ] Connect the recorded betting-history tabs to each game's own-bet list/detail APIs.
- [ ] For withdrawal history, include requested/rejected requests from `/api/payment/withdrawal/request/history`; `/api/payment/withdrawal/history` lists completed withdrawal transactions and does not represent all pending requests.

Acceptance: imported/backfilled results remain in the intended date order, AM/PM values stay under the correct date, and a customer sees their pending as well as completed withdrawals.

## Other issues to address before sign-off

These are source/integration findings, rather than extra features demonstrated by the recording.

- [ ] **Correct API authentication documentation.** [config/scribe.php](config/scribe.php) sets auth `enabled` and `default` to false. Generated [endpoint metadata](.scribe/endpoints/00.yaml) marks protected routes such as `api/wallet` unauthenticated, while [actual routes](routes/api.php) require Sanctum access abilities. Document public routes, access versus refresh tokens, and admin restrictions, then regenerate the API artifacts.
- [ ] **Reconcile old tests with the current contract.** [PaymentWorkflowTest](tests/Feature/PaymentWorkflowTest.php) imports the absent `App\Models\PaymentTransactions` and calls legacy `/api/payments` routes. [ModelSchemaTest](tests/Feature/ModelSchemaTest.php) includes an assertion that payment methods have no status column, although the current [migration](database/migrations/2026_10_21_032647_complete_betting_results_and_account_management.php) adds one. These are source-confirmed stale expectations; no fresh test-failure count is claimed. Update coverage rather than deleting tests without review.
- [ ] **Verify the running schema and queue.** Confirm the database has the required migrations and that queued result calculation runs/retries before payout confirmation. Route listing and PHP syntax checks do not establish either.
- [ ] **Review customer payment-history scale.** Customer deposit, withdrawal-transaction and withdrawal-request history currently call `get()` without pagination/filters in [PaymentService](app/Service/PaymentService.php). Add pagination if histories will be large; admin queues, wallet movements and game history already paginate.

Optional payment hardening: historical account/provider name snapshots and reconciliation of reused transfer evidence deserve a separate review. Client-reference idempotency prevents retries with the same key; it does not establish that a provider transfer submitted under a different key is new. The video does not demonstrate provider verification, so this review does not assume such integration is required or present.

## Suggested review order

1. Confirm frontend location, payout meaning, schedule policy and helper/import rules.
2. Finish schedule/schema alignment and valid odds setup.
3. Connect the existing payment/wallet/betting APIs to the customer screens.
4. Complete the number helpers, date grouping and history states.
5. Correct documentation and stale tests, then perform isolated automated and running-environment checks.

Before marking complete, verify deposit approval once, withdrawal reservation/refund once, owner/admin restrictions, atomic batch failure, retry idempotency, leading zeros, cutoff boundaries, queued calculation, payout confirmation once and cancellation refunds. Use the intended MySQL/MariaDB engine for concurrent requests; SQLite alone does not establish row-lock behavior. The existing [manual testing guide](MANUAL_TESTING.md) can supply detailed scenarios after its expectations are aligned with the decisions above.

## Checks completed and limits

- Inspected the actual video frames and current routes, services, requests, resources, models, migrations, seeders, test sources and frontend inventory.
- Confirmed installed direct package versions with Composer: Laravel 13.35.0, Sanctum 4.3.3, Scribe 5.11.0, Pest 5.3.1 and Pint 1.32.1, using PHP 8.5. Composer needed its CLI OpenSSL extension enabled; project configuration was not edited.
- API route listing succeeded with **58 route definitions**. The `{game}` definitions cover both game types; 58 is the route-list count, not a count of all expanded URLs or tested endpoints.
- PHP syntax checks passed for **158 files** under `app`, `routes`, `database` and `config`.
- `git diff --check` passed before this report was created; the report was also checked after writing.
- Temporary video-decoding tools and preview images were stored outside the project; application dependencies were unchanged.
- No tests, migration execution/rollback, seeding, live database inspection, live API/browser flows, mail delivery or concurrency verification were performed. Earlier test results mentioned in other documents were not rerun and are not current evidence here.

Only this report was added by this review. Existing source changes and earlier reports were preserved.
