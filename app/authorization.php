<?php

declare(strict_types=1);

final class Authorization
{
    private static array $cache = [];

    public static function can(array $user, string $permission): bool
    {
        $role = (string) ($user['role'] ?? '');
        if ($role === 'super_admin') {
            return true;
        }

        if (!array_key_exists($role, self::$cache)) {
            $rows = DB::fetchAll(
                'SELECT p.slug FROM permissions p
                 INNER JOIN role_permissions rp ON rp.permission_id = p.id
                 INNER JOIN roles r ON r.id = rp.role_id
                 WHERE r.slug = ?',
                [$role]
            );
            self::$cache[$role] = array_fill_keys(array_column($rows, 'slug'), true);
        }

        return isset(self::$cache[$role][$permission]);
    }

    public static function require(array $user, string $permission): void
    {
        if (!self::can($user, $permission)) {
            throw new RuntimeException('Bu işlem için rolünüzün yetkisi yok.');
        }
    }

    public static function roles(): array
    {
        return DB::fetchAll('SELECT id, slug, name, is_system FROM roles ORDER BY sort_order, name');
    }

    public static function permissionGroups(): array
    {
        $groups = [];
        foreach (DB::fetchAll('SELECT id, slug, name, permission_group FROM permissions ORDER BY permission_group, name') as $permission) {
            $groups[$permission['permission_group']][] = $permission;
        }

        return $groups;
    }

    public static function rolePermissionIds(int $roleId): array
    {
        return array_map('intval', array_column(DB::fetchAll('SELECT permission_id FROM role_permissions WHERE role_id = ?', [$roleId]), 'permission_id'));
    }

    public static function saveRolePermissions(array $actor, int $roleId, array $permissionIds): void
    {
        self::require($actor, 'roles.manage');
        $role = DB::fetch('SELECT * FROM roles WHERE id = ?', [$roleId]);
        if (!$role || in_array($role['slug'], ['super_admin', 'customer'], true)) {
            throw new RuntimeException('Bu rolün yetkileri bu ekrandan değiştirilemez.');
        }

        $validIds = array_map('intval', array_column(DB::fetchAll('SELECT id FROM permissions'), 'id'));
        $permissionIds = array_values(array_unique(array_intersect(array_map('intval', $permissionIds), $validIds)));

        DB::pdo()->beginTransaction();
        try {
            DB::execute('DELETE FROM role_permissions WHERE role_id = ?', [$roleId]);
            foreach ($permissionIds as $permissionId) {
                DB::insert('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permissionId]);
            }
            Audit::record((int) $actor['id'], 'role.permissions_updated', 'role', $roleId, ['permission_ids' => $permissionIds]);
            DB::pdo()->commit();
        } catch (Throwable $e) {
            DB::pdo()->rollBack();
            throw $e;
        }

        self::$cache = [];
    }
}

function can(array $user, string $permission): bool
{
    return Authorization::can($user, $permission);
}
