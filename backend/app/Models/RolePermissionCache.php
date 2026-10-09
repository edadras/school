<?php

namespace App\Models;

use Illuminate\Support\Facades\DB;

/** Per-request memo of a user's permission keys in a school (kept tiny; no cross-request staleness). */
class RolePermissionCache
{
    private static array $memo = [];

    public static function userHas(int $userId, int $schoolId, string $permission): bool
    {
        $k = "$userId:$schoolId";

        self::$memo[$k] ??= DB::table('school_user_memberships as m')
            ->join('role_permissions as rp', 'rp.role_id', '=', 'm.role_id')
            ->join('permissions as p', 'p.id', '=', 'rp.permission_id')
            ->where('m.user_id', $userId)->where('m.school_id', $schoolId)->where('m.status', 'active')
            ->pluck('p.key')->all();

        return in_array($permission, self::$memo[$k], true);
    }

    public static function flush(): void
    {
        self::$memo = [];
    }
}
