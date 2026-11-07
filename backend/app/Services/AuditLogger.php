<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * §21 audit trail — every mutation in the system goes through here so
 * all later phases get history for free.
 */
class AuditLogger
{
    /**
     * Write one audit row for the current authenticated user.
     *
     * @param  array<string, mixed>|null  $old  Previous attributes (updates/deletes).
     * @param  array<string, mixed>|null  $new  New attributes (creates/updates).
     */
    public static function log(
        string $action,
        string $table,
        int|string|null $recordId,
        ?array $old = null,
        ?array $new = null,
    ): AuditLog {
        return AuditLog::create([
            'user_id' => Auth::id(),
            'action' => $action,
            'table_name' => $table,
            'record_id' => $recordId,
            'old_values' => self::redact($old),
            'new_values' => self::redact($new),
            'ip_address' => Request::ip(),
            'user_agent' => substr((string) Request::userAgent(), 0, 255),
            'created_at' => now(),
        ]);
    }

    private static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        foreach ($values as $key => $value) {
            if (preg_match('/password|token|secret|request_hash/i', (string) $key)) {
                unset($values[$key]);
            } elseif (is_array($value)) {
                $values[$key] = self::redact($value);
            }
        }

        return $values;
    }
}
