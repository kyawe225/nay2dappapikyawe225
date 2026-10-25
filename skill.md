---
name: extend-2dapp-api
description: Continue the Laravel 2D/3D app in this repository using its existing authentication, payment, wallet, draw, entry, and result code. Use the companion plan and database design to implement an agreed phase or review its gaps.
---

# Extend the existing 2D/3D app

Read [IMPLEMENTATION_PLAN.md](IMPLEMENTATION_PLAN.md) for the video comparison, phases, proposed API contracts, and acceptance criteria. Read [DATABASE_STRUCTURE.md](DATABASE_STRUCTURE.md) before changing migrations, models, wallet accounting, or game settlement. Recheck the relevant source files because these documents describe a dated working-tree snapshot.

This file is the requested portable project guidance. It is saved as `skill.md` in the repository; it has not been installed in a Codex skill directory. A request to read or follow this file supplies the context for its use.

## Choose the work

- Follow `AGENTS.md` and any applicable `.ai/rules` first. Confirm installed versions with Composer and `package.json`; the reviewed installation uses PHP 8.5 and Laravel 13.
- Use the phase the user requests. Otherwise, find the earliest unfinished dependency in the plan and make its status concrete before starting a dependent feature.
- Preserve existing uncommitted work. Missing migration files are a repair decision, not permission to restore every deleted file or run a destructive database reset.
- Treat video observations, code already written, proposed behavior, and unresolved business rules as separate evidence. A screen does not establish a working backend or a payment integration.
- Keep API work in the current structure: `app/Http/Controllers/web`, `app/Http/Controllers/admin`, `app/Http/Requests`, `app/Http/Resources`, and `app/Service`. Reuse `PaymentService`, `AuthService`, and the existing models before creating alternatives.

## Preserve the important contracts

- Keep existing `/api` routes and their response conventions compatible. Follow existing API Resources for new domain endpoints. Do not introduce a new API version or rename the existing services solely for consistency.
- Treat domain IDs as UUID strings throughout migrations, request validation, route constraints, relationships, resources, and idempotency comparisons. Preserve the custom UUID-based personal access tokens and the existing key types of other framework tables.
- Retain `users.balance` as the spendable wallet balance and `transactions` as its append-only movement history. Store money in whole MMK units. Use the same database transaction for the balance update, source record, and movement record.
- Retain payment behavior: a deposit credits only on approval; a withdrawal debits when submitted; withdrawal approval never debits again; rejection or cancellation refunds once. Preserve `PaymentService`'s user-before-payment lock order. For game operations, define a consistent draw-before-user-before-entry order and avoid introducing a reverse lock dependency.
- Keep the existing separate `twod_*` and `threed_*` tables. Preserve numbers as fixed-width strings, including `00` and `000`. Snapshot the payout multiplier when accepting an entry.
- Determine draw availability and cutoff on the server. Serialize competing placement, cancellation, and settlement operations around a consistent draw lock and recheck state inside the transaction. Use exact decimal or scaled-integer payout arithmetic with a defined rounding rule.
- Make retries safe with a database uniqueness rule and a comparison of the validated business payload. Reusing a request key with a changed payload must conflict. Never let retrying a bet, payout, or refund change the wallet twice.
- For the recorded saved-account and 3D multi-number screens, use the companion schema's small account and slip-header extensions. Keep destination snapshots on payments and individual wagers in the existing entry tables. A text import previews explicit entries before an atomic purchase; it does not itself debit funds.
- Scope customer reads to the authenticated owner. Reuse active-role administrator checks for administrative writes. Seeded Branch Manager and Staff roles do not imply implemented branch ownership or commission features.

## Verify the chosen phase

For non-trivial API work, follow the available `postman:api-engineer` skill and its relevant route. Reuse Scribe output and existing tests as inputs, while checking them against current routes and Form Requests; generated documentation in this snapshot is incomplete. Do not publish shared workspace changes without authorization.

Use Artisan generators for new PHP files. Reuse factories and the existing Pest feature tests. Before adding or editing tests, locate and read the project's `testing-best-practices` skill if available; disclose a missing required skill rather than claiming to have followed it.

Run the narrowest relevant tests and the project's required Pint command after PHP edits. Use an isolated test database; SQLite tests do not establish production row-locking behavior. Report commands actually run and remaining gaps. After feature tests pass, ask the user to run `php artisan test --compact`, as required by `AGENTS.md`.

A planning-only request authorizes updating these documents. It does not by itself request implementing the phases, applying migrations, clearing runtime caches, changing dependencies, or publishing anything.
