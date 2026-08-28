<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const OLD_NAME = 'manage_override_notifications';

    private const NEW_NAME = 'manage_follow_up_alerts';

    public function up(): void
    {
        self::rename(self::OLD_NAME, self::NEW_NAME);
    }

    public function down(): void
    {
        self::rename(self::NEW_NAME, self::OLD_NAME);
    }

    

    private static function rename(string $from, string $to): void
    {
        $guard = 'web';

        $toId = DB::table('permissions')
            ->where('name', $to)
            ->where('guard_name', $guard)
            ->value('id');

        if ($toId === null) {
            $now = now();

            DB::table('permissions')->insert([
                'name' => $to,
                'guard_name' => $guard,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $toId = (int) DB::table('permissions')
                ->where('name', $to)
                ->where('guard_name', $guard)
                ->value('id');
        }

        $fromId = DB::table('permissions')
            ->where('name', $from)
            ->where('guard_name', $guard)
            ->value('id');

        if ($fromId === null || (int) $fromId === (int) $toId) {
            return;
        }

        $roleRows = DB::table('role_has_permissions')
            ->where('permission_id', $fromId)
            ->get(['role_id'])
            ->map(fn ($row) => ['permission_id' => $toId, 'role_id' => $row->role_id])
            ->all();

        if ($roleRows !== []) {
            DB::table('role_has_permissions')->insertOrIgnore($roleRows);
        }

        $modelRows = DB::table('model_has_permissions')
            ->where('permission_id', $fromId)
            ->get(['model_id', 'model_type'])
            ->map(fn ($row) => [
                'permission_id' => $toId,
                'model_id' => $row->model_id,
                'model_type' => $row->model_type,
            ])
            ->all();

        if ($modelRows !== []) {
            DB::table('model_has_permissions')->insertOrIgnore($modelRows);
        }

        DB::table('role_has_permissions')->where('permission_id', $fromId)->delete();
        DB::table('model_has_permissions')->where('permission_id', $fromId)->delete();
        DB::table('permissions')->where('id', $fromId)->delete();

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }
};
