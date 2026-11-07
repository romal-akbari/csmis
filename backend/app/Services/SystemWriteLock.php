<?php

namespace App\Services;

use Closure;
use Illuminate\Support\Facades\DB;

/** Connection-owned mutex also survives MySQL's implicit commits during DDL. */
class SystemWriteLock
{
    public function run(Closure $work): mixed
    {
        $name = 'csms-write-'.substr(hash('sha256', DB::getDatabaseName()), 0, 40);
        $lock = DB::selectOne('SELECT GET_LOCK(?, 30) AS acquired', [$name]);
        abort_unless((int) $lock->acquired === 1, 503,
            'System maintenance is running. Please try again.');
        try {
            return $work();
        } finally {
            DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$name]);
        }
    }
}
