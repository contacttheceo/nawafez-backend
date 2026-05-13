<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class AuditLogger
{
    /**
     * Record an admin action. Silently swallows errors so a failed
     * audit-log write never breaks the underlying admin operation.
     *
     * @param  User                            $admin
     * @param  string                          $action       e.g. 'listing.approve'
     * @param  string|null                     $targetType   'listing' | 'user' | 'verification' | 'report' | null
     * @param  int|null                        $targetId
     * @param  array<string,mixed>             $metadata
     * @param  Request|null                    $request      to capture IP + UA
     */
    public static function log(
        User $admin,
        string $action,
        ?string $targetType = null,
        ?int $targetId = null,
        array $metadata = [],
        ?Request $request = null
    ): void {
        try {
            AuditLog::create([
                'admin_id'    => $admin->id,
                'action'      => $action,
                'target_type' => $targetType,
                'target_id'   => $targetId,
                'metadata'    => $metadata,
                'ip_address'  => $request?->ip(),
                'user_agent'  => $request?->userAgent(),
            ]);
        } catch (\Throwable $e) {
            Log::warning("AuditLogger: failed to log '{$action}' — {$e->getMessage()}");
        }
    }
}
