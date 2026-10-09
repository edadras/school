<?php

namespace App\Modules\Auth;

use App\Models\Permission;
use App\Models\Role;
use App\Models\RolePermissionCache;

class RbacSync
{
    public static function run(): void
    {
        $all = config('rbac.permissions');

        foreach ($all as $key => $desc) {
            Permission::updateOrCreate(['key' => $key], ['description' => $desc]);
        }

        foreach (config('rbac.roles') as $key => $def) {
            $role = Role::updateOrCreate(['key' => $key], ['name' => $def['name'], 'scope' => 'school', 'is_system' => true]);
            $keys = $def['permissions'] === ['*'] ? array_keys($all) : $def['permissions'];
            $role->permissions()->sync(Permission::whereIn('key', $keys)->pluck('id'));
        }

        RolePermissionCache::flush();
    }
}
