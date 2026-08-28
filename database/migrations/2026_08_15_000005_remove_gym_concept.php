<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    private const GYM_ID_TABLES = [
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
        foreach (self::GYM_ID_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint): void {
                $blueprint->dropForeign(['gym_id']);
            });

            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropIndex("{$table}_gym_id_index");
                $blueprint->dropColumn('gym_id');
            });
        }

        Schema::dropIfExists('plan_locations');
        Schema::dropIfExists('service_locations');
        Schema::dropIfExists('gyms');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('all_locations');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('all_locations')->default(true);
        });

        Schema::create('gyms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('status')->default('active');
            $table->timestamps();
        });

        foreach (self::GYM_ID_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('gym_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('gyms')
                    ->cascadeOnDelete();

                $blueprint->index('gym_id', "{$table}_gym_id_index");
            });
        }

        Schema::create('service_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['service_id', 'location_id']);
        });

        Schema::create('plan_locations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['plan_id', 'location_id']);
        });
    }
};
