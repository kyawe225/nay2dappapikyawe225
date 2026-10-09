<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Laravel\Sanctum\PersonalAccessToken as ScamtumPersonalAccessToken;

class PersonalAccessToken extends ScamtumPersonalAccessToken
{
    use HasUuids;

    public $incrementing = false;

    protected $keyType = 'string';

}
