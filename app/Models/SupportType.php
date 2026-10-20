<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'image_location'])]
class SupportType extends Model
{
    use HasUuids;

    public function support(): HasMany
    {
        return $this->hasMany(Support::class, 'support_type_id');
    }
}
