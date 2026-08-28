<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    private const TENANT_TABLES = [
        'locations',
        'users',
        'members',
        'plans',
        'services',
        'subscriptions',
        'plan_check_ins',
        'invoices',
        'invoice_transactions',
        'expenses',
        'enquiries',
        'follow_ups',
        'queue_entries',
        'member_applications',
        'location_tokens',
    ];

    public function up(): void
    {
        foreach (self::TENANT_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('gym_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('gyms')
                    ->cascadeOnDelete();

                $blueprint->index('gym_id', "{$table}_gym_id_index");
            });
        }

        
        
        $defaultGymId = DB::table('gyms')->insertGetId([
            'name' => 'Default Gym',
            'slug' => 'default',
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach (self::TENANT_TABLES as $table) {
            DB::table($table)->update(['gym_id' => $defaultGymId]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign(['gym_id']);
                $blueprint->dropIndex("{$table}_gym_id_index");
                $blueprint->dropColumn('gym_id');
            });
        }

        DB::table('gyms')->where('slug', 'default')->delete();
    }
};
