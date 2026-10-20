<?php

use App\Models\PaymentMethod;
use App\Models\ThreedDraw;
use App\Models\ThreedEntry;
use App\Models\Transaction;
use App\Models\TwodDraw;
use App\Models\TwodEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

test('game factories persist against the migrated tables and preserve leading zeros', function (string $drawClass, string $entryClass): void {
    $draw = $drawClass::factory()->create(['winning_number' => '00']);
    $user = User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create();
    $entry = $entryClass::factory()->create(['draw_number' => '01', 'user_id' => $user->id]);

    expect(Str::isUuid($draw->id))->toBeTrue()
        ->and($draw->fresh()->winning_number)->toBe('00')
        ->and($entry->fresh()->draw_number)->toBe('01')
        ->and($entry->user->id)->toBe($entry->user_id);

    $draw->delete();
    expect($drawClass::query()->find($draw->id))->toBeNull()
        ->and($drawClass::withTrashed()->find($draw->id)->trashed())->toBeTrue();
})->with([
    '2D' => [TwodDraw::class, TwodEntry::class],
    '3D' => [ThreedDraw::class, ThreedEntry::class],
]);

test('transactions use current foreign keys and preserve decimal amounts', function (): void {
    $payer = User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create();
    $transaction = Transaction::factory()->create(['amount' => '1000.25', 'payment_user_id' => $payer->id]);
    $user = $transaction->user;
    $paymentMethod = $transaction->paymentMethod;

    expect($transaction->fresh()->amount)->toBe('1000.25')
        ->and($user->id)->toBe($transaction->payment_user_id)
        ->and($paymentMethod->payments->modelKeys())->toContain($transaction->id)
        ->and($transaction->approver)->toBeNull()
        ->and(Transaction::query()->filter(['payment_user_id' => $user->id, 'status' => 'pending'])->count())->toBe(1);
});

test('payment methods have no soft delete or status columns in the current schema', function (): void {
    $method = PaymentMethod::factory()->create();
    expect($method->fresh()->type)->toBe('deposit');
    $method->delete();
    expect(PaymentMethod::query()->find($method->id))->toBeNull();
});
