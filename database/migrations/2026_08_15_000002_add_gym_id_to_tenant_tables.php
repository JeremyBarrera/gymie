<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tables that hold business data owned by a gym (tenant).
     *
     * Pivot tables (`user_locations`, `plan_locations`, `service_locations`) are
     * intentionally excluded: reads on them are already restricted by the
     * `locations.gym_id` global scope on the joined model, and every write path
     * is validated against the editor's accessible locations, so a pivot row
     * can never reference a location from another gym.
     */
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

        // Create the default gym and claim every existing row for it, so
        // single-tenant installations keep working untouched.
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
