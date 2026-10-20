<?php

use App\Models\Support;
use App\Models\SupportType;
use App\Models\User;
use Database\Seeders\SupportSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    $this->seed(SupportSeeder::class);
});

test('support seed data has valid ids relationships and public jpeg images', function (): void {
    expect(SupportType::query()->count())->toBe(3)
        ->and(Support::query()->count())->toBe(3);

    foreach (Support::with('supportType')->get() as $support) {
        expect(Str::isUuid($support->id))->toBeTrue()
            ->and(Str::isUuid($support->supportType->id))->toBeTrue()
            ->and($support->supportType->support->modelKeys())->toContain($support->id)
            ->and($support->status)->toBe('inactive')
            ->and($support->link)->toBeNull();
        Storage::disk('public')->assertExists($support->supportType->image_location);
        expect(getimagesize(Storage::disk('public')->path($support->supportType->image_location))['mime'])->toBe('image/jpeg');
    }
});

test('support seeding preserves configured contacts and does not duplicate them', function (): void {
    $support = Support::query()->firstOrFail();
    $support->update(['link' => 'https://example.com/support', 'status' => 'active']);
    $ids = Support::query()->orderBy('id')->pluck('id')->all();
    $this->seed(SupportSeeder::class);

    expect(Support::query()->orderBy('id')->pluck('id')->all())->toBe($ids)
        ->and(SupportType::query()->count())->toBe(3)
        ->and($support->fresh()->link)->toBe('https://example.com/support')
        ->and($support->fresh()->status)->toBe('active');
});

test('support endpoint requires authentication', function (): void {
    $this->getJson('/api/support')->assertUnauthorized();
});

test('support endpoint returns active contacts with names and public type images', function (): void {
    Sanctum::actingAs(User::factory()->afterMaking(function (User $user): void {
        unset($user->role_id);
    })->create(), ['access']);
    $support = Support::query()->firstOrFail();
    $support->update(['status' => 'active', 'link' => 'https://example.com/support']);

    $this->getJson('/api/support')->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $support->id)
        ->assertJsonPath('data.0.name', $support->name)
        ->assertJsonPath('data.0.supportType.name', $support->supportType->name)
        ->assertJsonPath('data.0.supportType.imageLocation', Storage::disk('public')->url($support->supportType->image_location));
});
