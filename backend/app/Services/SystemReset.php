<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;

class SystemReset
{
    public const AUTH_TABLES = ['users', 'roles', 'permissions',
        'user_roles', 'role_permissions'];

    private const KEEP = [...self::AUTH_TABLES, 'migrations', 'company_settings'];

    private const RUNTIME = ['cache', 'cache_locks', 'sessions',
        'personal_access_tokens', 'password_reset_tokens'];

    public function authorize(User $actor): User
    {
        $fresh = User::find($actor->id);
        abort_unless($fresh?->is_active && FoundationAccess::administrator($fresh), 403);
        FoundationAccess::permit($fresh, 'settings.update');

        return $fresh;
    }

    private function plan(): array
    {
        $tables = DB::select('SELECT TABLE_NAME AS name, ENGINE AS engine FROM '
            .'information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() '
            ."AND TABLE_TYPE='BASE TABLE' ORDER BY TABLE_NAME");
        $counts = [];
        foreach ($tables as $table) {
            if (! preg_match('/^[a-zA-Z0-9_]+$/', $table->name)
                || strtoupper($table->engine ?? '') !== 'INNODB') {
                throw new RuntimeException('All reset tables must support transactional recovery.');
            }
            if (! in_array($table->name, self::KEEP, true)) {
                $counts[$table->name] = DB::table($table->name)->count();
            }
        }
        // A future link from a preserved account to deleted business data must
        // receive an explicit reset policy before it can be cleared safely.
        $references = DB::table('information_schema.KEY_COLUMN_USAGE')
            ->where('TABLE_SCHEMA', DB::getDatabaseName())
            ->whereIn('TABLE_NAME', self::AUTH_TABLES)
            ->whereNotNull('REFERENCED_TABLE_NAME')->get();
        foreach ($references as $reference) {
            if (! in_array($reference->REFERENCED_TABLE_NAME, self::KEEP, true)
                && ! ($reference->TABLE_NAME === 'users'
                    && $reference->COLUMN_NAME === 'employee_id'
                    && $reference->REFERENCED_TABLE_NAME === 'employees')) {
                throw new RuntimeException('A preserved authentication relationship needs review.');
            }
        }

        return $counts;
    }

    private function digest(array $counts): string
    {
        return hash('sha256', json_encode(array_diff_key($counts,
            array_flip(self::RUNTIME)), JSON_THROW_ON_ERROR));
    }

    public function preview(User $actor): array
    {
        $this->authorize($actor);

        return DB::transaction(function () use ($actor) {
            $settings = DB::table('company_settings')->where('id', 1)
                ->lockForUpdate()->firstOrFail();
            $counts = $this->plan();
            $expires = now()->addMinutes(5);
            $kept = [];
            foreach (self::AUTH_TABLES as $table) {
                $kept[$table] = DB::table($table)->count();
            }

            return ['records_to_remove' => array_sum($counts),
                'kept' => $kept, 'expires_at' => $expires->toIso8601String(),
                'preview_token' => Crypt::encryptString(json_encode([
                    'actor' => $actor->id, 'version' => (int) $settings->reset_version,
                    'expires' => $expires->timestamp, 'digest' => $this->digest($counts),
                    'nonce' => (string) Str::uuid(),
                ], JSON_THROW_ON_ERROR))];
        });
    }

    private function verifyPreview(string $token, User $actor, object $settings, array $counts): void
    {
        try {
            $preview = json_decode(Crypt::decryptString($token), true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw ValidationException::withMessages([
                'preview_token' => 'Review the reset again before continuing.',
            ]);
        }
        abort_unless(($preview['actor'] ?? null) === $actor->id
            && ($preview['version'] ?? null) === (int) $settings->reset_version
            && ($preview['expires'] ?? 0) > now()->timestamp
            && hash_equals($this->digest($counts), $preview['digest'] ?? ''),
            409, 'System data changed or the review expired. Review the reset again.');
    }

    private function authenticationFingerprint(): string
    {
        $data = [];
        foreach (self::AUTH_TABLES as $table) {
            $rows = DB::table($table)->get()->map(function ($row) use ($table) {
                $row = (array) $row;
                if ($table === 'users') {
                    unset($row['employee_id']);
                }

                return json_encode($row, JSON_THROW_ON_ERROR);
            })->all();
            sort($rows, SORT_STRING);
            $data[$table] = $rows;
        }

        return hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
    }

    public function execute(User $actor, #[\SensitiveParameter] string $password,
        #[\SensitiveParameter] string $token): array
    {
        return app(SystemWriteLock::class)->run(function () use ($actor, $password, $token) {
            $archive = null;
            try {
                return DB::transaction(function () use ($actor, $password, $token, &$archive) {
                    $settings = DB::table('company_settings')->where('id', 1)
                        ->lockForUpdate()->firstOrFail();
                    $actor = $this->authorize($actor);
                    if (! Hash::check($password, $actor->password_hash)) {
                        throw ValidationException::withMessages([
                            'password' => 'Your current password is incorrect.',
                        ]);
                    }
                    $counts = $this->plan();
                    $this->verifyPreview($token, $actor, $settings, $counts);
                    $id = (string) Str::uuid();
                    $directory = rtrim(config('system_reset.backup_root'), '/\\').'/'.$id;
                    $backup = app(DatabaseBackup::class)->create($directory);
                    $protected = $this->authenticationFingerprint();
                    $archive = app(SystemResetFiles::class)->archive($directory);
                    if ((int) DB::selectOne('SELECT @@FOREIGN_KEY_CHECKS AS enabled')->enabled !== 1) {
                        throw new RuntimeException('Foreign key checks must be enabled before reset.');
                    }
                    try {
                        DB::statement('SET @csms_system_reset = 1');
                        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                        // Imported user tables may auto-update this timestamp. Unlinking
                        // deleted employees must preserve every other account field.
                        DB::table('users')->whereNotNull('employee_id')->update([
                            'employee_id' => null, 'updated_at' => DB::raw('updated_at'),
                        ]);
                        foreach (array_keys($counts) as $table) {
                            DB::table($table)->delete();
                        }
                        DB::table('company_settings')->where('id', 1)->update([
                            'base_currency_id' => null, 'default_locale' => 'en',
                            'timezone' => 'Asia/Kabul', 'reminder_days_before' => 3,
                            'updated_by' => null, 'created_at' => now(), 'updated_at' => now(),
                            'reset_version' => (int) $settings->reset_version + 1,
                        ]);
                    } finally {
                        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                        DB::statement('SET @csms_system_reset = NULL');
                    }
                    if (! hash_equals($protected, $this->authenticationFingerprint())) {
                        throw new RuntimeException('Authentication preservation failed; reset rolled back.');
                    }
                    foreach (array_keys($counts) as $table) {
                        if (DB::table($table)->exists()) {
                            throw new RuntimeException('Reset verification failed; changes rolled back.');
                        }
                    }
                    $manifest = ['reset_id' => $id, 'actor_id' => $actor->id,
                        'performed_at' => now()->toIso8601String(), 'removed' => $counts,
                        'authentication_sha256' => $protected, 'database_backup' => basename($backup['path']),
                        'database_sha256' => $backup['sha256'], 'images_archived' => $archive !== null];
                    if (file_put_contents($directory.'/reset.json', json_encode($manifest,
                        JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)) === false) {
                        throw new RuntimeException('The private reset receipt could not be saved.');
                    }
                    chmod($directory.'/reset.json', 0600);

                    return ['reset_id' => $id, 'backup_created' => true, 'signed_out' => true];
                });
            } catch (Throwable $error) {
                app(SystemResetFiles::class)->restore($archive);
                throw $error;
            }
        });
    }
}
