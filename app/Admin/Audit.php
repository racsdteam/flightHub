<?php

namespace App\Admin;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * admin_audit (append-only, kept forever). Never a password, TOTP secret or
 * code, recovery code, API key or session id: callers pass only safe details.
 */
final class Audit
{
    /**
     * @param  array<string, mixed>  $details  before / after values; never secrets
     * @param  string|null  $actor  the user's email, "cli" or "hub"; default the signed-in user, else "hub"
     */
    public static function record(
        string $action,
        array $details = [],
        ?string $targetType = null,
        string|int|null $targetId = null,
        ?string $actor = null,
        ?int $userId = null,
    ): void {
        $user = Auth::user();
        $http = ! app()->runningInConsole() || app()->runningUnitTests();
        $request = $http ? request() : null;

        DB::table('admin_audit')->insert([
            'at' => CarbonImmutable::now('UTC')->format('Y-m-d H:i:s.u'),
            'user_id' => $userId ?? $user?->id,
            'actor' => mb_substr($actor ?? $user?->email ?? 'hub', 0, 100),
            'action' => $action,
            'target_type' => $targetType,
            'target_id' => $targetId === null ? null : (string) $targetId,
            'details' => $details === [] ? null : json_encode($details, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'ip' => $request?->ip(),
            'user_agent' => $request?->userAgent() === null ? null : mb_substr($request->userAgent(), 0, 255),
        ]);
    }
}
