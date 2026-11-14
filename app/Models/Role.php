<?php

namespace App\Models;

use Database\Factories\RoleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable(['role_name', 'status'])]
class Role extends Model
{
    /** @use HasFactory<RoleFactory> */
    use HasFactory, SoftDeletes;

    protected $primaryKey = 'role_name';

    public $incrementing = false;

    protected $keyType = 'string';

    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'role_name', 'role_name');
    }
}
