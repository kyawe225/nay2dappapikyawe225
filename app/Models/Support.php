<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['support_type_id', 'name', 'type', 'value', 'status'])]
class Support extends Model
{
    use HasUuids;

    public function supportType(): BelongsTo
    {
        return $this->belongsTo(SupportType::class, 'support_type_id');
    }
}
