<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $permissions = [
            ['name' => 'billing.invoice.edit', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
            ['name' => 'billing.tax.edit', 'guard_name' => 'web', 'created_at' => now(), 'updated_at' => now()],
        ];

        foreach ($permissions as $permission) {
            DB::table('permissions')->updateOrInsert(
                ['name' => $permission['name'], 'guard_name' => $permission['guard_name']],
                $permission
            );
        }
    }

    public function down(): void
    {
        DB::table('permissions')->whereIn('name', ['billing.invoice.edit', 'billing.tax.edit'])->delete();
    }
};
