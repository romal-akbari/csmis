<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Symfony\Component\Process\Process;

class DatabaseBackup
{
    public function create(?string $directory = null): array
    {
        $directory ??= storage_path('app/private/backups');
        if (! is_dir($directory) && ! mkdir($directory, 0700, true)) {
            throw new RuntimeException('The private backup directory could not be created.');
        }
        $config = DB::connection()->getConfig();
        $path = $directory.'/'.date('Ymd_His').'_'.bin2hex(random_bytes(4)).'.sql';
        $binary = env('MYSQLDUMP_BINARY', PHP_OS_FAMILY === 'Windows'
            ? 'C:/xampp/mysql/bin/mysqldump.exe' : 'mysqldump');
        $environment = ['MYSQL_PWD' => $config['password'] ?? ''];
        if (PHP_OS_FAMILY === 'Windows') {
            // HTTP SAPIs may omit Windows variables from $_SERVER. Symfony's
            // filtered default environment then lacks the Winsock provider root.
            $environment['SystemRoot'] = getenv('SystemRoot') ?: 'C:\\Windows';
            $environment['WINDIR'] = getenv('WINDIR') ?: $environment['SystemRoot'];
        }
        $process = new Process([
            $binary, '--host='.$config['host'], '--port='.$config['port'],
            '--user='.$config['username'], '--single-transaction', '--quick',
            '--skip-lock-tables', '--default-character-set=utf8mb4',
            '--routines', '--triggers', '--hex-blob',
            '--result-file='.$path, $config['database'],
        ], null, $environment);
        $process->setTimeout(120);
        $process->run();
        if (! $process->isSuccessful() || ! is_file($path) || filesize($path) === 0) {
            Log::error('Private database backup failed.', [
                'exit_code' => $process->getExitCode(),
                'diagnostic' => trim($process->getErrorOutput()),
                'database' => $config['database'],
            ]);
            throw new RuntimeException('Backup failed. No reset was performed.');
        }
        chmod($path, 0600);

        return ['path' => $path, 'sha256' => hash_file('sha256', $path)];
    }
}
