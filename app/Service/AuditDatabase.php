<?php

namespace App\Service;

use Illuminate\Database\ConnectionInterface;
use Illuminate\Support\Facades\DB;

class AuditDatabase
{
    public function Connection(): ConnectionInterface
    {
        return DB::connection();
    }
}
