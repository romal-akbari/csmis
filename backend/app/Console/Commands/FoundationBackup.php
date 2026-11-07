<?php

namespace App\Console\Commands;

use App\Services\DatabaseBackup;
use Illuminate\Console\Command;
use Throwable;

class FoundationBackup extends Command
{
    protected $signature = 'foundation:backup';

    protected $description = 'Create a private consistent SQL backup';

    public function handle(): int
    {
        try {
            $backup = app(DatabaseBackup::class)->create();
        } catch (Throwable) {
            $this->error('Backup failed. Check database and dump binary configuration.');

            return self::FAILURE;
        }
        $this->info($backup['path']);
        $this->line('SHA256 '.$backup['sha256']);

        return self::SUCCESS;
    }
}
