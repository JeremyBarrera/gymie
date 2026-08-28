<?php

use App\Models\Location;
use App\Models\Service;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    private const LOCATION_TABLES = [
        'services',
        'plans',
        'subscriptions',
        'invoices',
        'invoice_transactions',
        'expenses',
        'enquiries',
        'follow_ups',
        'member_applications',
        'location_tokens',
    ];

    public function up(): void
    {
        foreach (self::LOCATION_TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->foreignId('location_id')
                    ->nullable()
                    ->after('id')
                    ->constrained('locations')
                    ->cascadeOnDelete();

                $blueprint->index('location_id', "{$table}_location_id_index");
            });
        }

        
        
        
        
        DB::table('location_tokens as lt')
            ->join('locations as l', 'l.id', '=', 'lt.tokenable_id')
            ->where('lt.tokenable_type', Location::class)
            ->whereNull('lt.location_id')
            ->pluck('l.id', 'lt.id')
            ->each(function (int $locationId, int $tokenId): void {
                DB::table('location_tokens')
                    ->where('id', $tokenId)
                    ->update(['location_id' => $locationId]);
            });

        DB::table('location_tokens as lt')
            ->join('services as s', 's.id', '=', 'lt.tokenable_id')
            ->where('lt.tokenable_type', Service::class)
            ->whereNull('lt.location_id')
            ->pluck('s.location_id', 'lt.id')
            ->each(function (int $locationId, int $tokenId): void {
                DB::table('location_tokens')
                    ->where('id', $tokenId)
                    ->update(['location_id' => $locationId]);
            });

        
        
        foreach (self::LOCATION_TABLES as $table) {
            DB::table($table)
                ->whereNull('location_id')
                ->update([
                    'location_id' => DB::raw(
                        '(SELECT MIN(id) FROM locations WHERE locations.gym_id = '.$table.'.gym_id)'
                    ),
                ]);
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::LOCATION_TABLES) as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table): void {
                $blueprint->dropForeign(['location_id']);
                $blueprint->dropIndex("{$table}_location_id_index");
                $blueprint->dropColumn('location_id');
            });
        }
    }
};
