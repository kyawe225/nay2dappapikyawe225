<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

#[Fillable(['name', 'email', 'password', 'role_name', 'status'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    protected $attributes = ['status' => 'active'];

    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable;

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_name', 'role_name');
    }

    public function payments(): HasMany
    {
        return $this->transactions();
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class, 'payment_user_id');
    }

    public function paymentAccounts(): HasMany
    {
        return $this->hasMany(PaymentAccount::class);
    }

    public function withdrawRequests(): HasMany
    {
        return $this->hasMany(WithdrawRequest::class, 'requested_payment_user_id');
    }

    public function wallets(): HasMany
    {
        return $this->hasMany(Wallet::class);
    }

    public function wallet(): HasOne
    {
        return $this->hasOne(Wallet::class);
    }

    public function twodEntries(): HasMany
    {
        return $this->hasMany(TwodEntry::class);
    }

    public function threedEntries(): HasMany
    {
        return $this->hasMany(ThreedEntry::class);
    }

    public function isSuperAdministrator(): bool
    {
        return $this->status === 'active' && $this->role()->where('status', 'active')->where('role_name', 'Super Admin')->exists();
    }

    public function isAdministrator(): bool
    {
        return $this->status === 'active' && $this->role()->where('status', 'active')->whereNull('deleted_at')
            ->whereIn('role_name', ['Super Admin', 'Admin', 'Staff'])->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}
