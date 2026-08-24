<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('plan_services', function (Blueprint $table) {
            $table->id();
            $table->foreignId('plan_id')->constrained()->cascadeOnDelete();
            $table->foreignId('service_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['plan_id', 'service_id']);
        });

        // Snapshot before dropping the column: drivers that rebuild the
        // table to drop a column (SQLite) fire the parent-table DELETE,
        // which would cascade-empty a pre-filled pivot.
        $links = DB::table('plans')
            ->whereNotNull('service_id')
            ->orderBy('id')
            ->get(['id', 'service_id']);

        Schema::table('plans', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('service_id');
        });

        $now = now();

        foreach ($links as $link) {
            DB::table('plan_services')->insertOrIgnore([
                'plan_id' => $link->id,
                'service_id' => $link->service_id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        // Snapshot before altering: rebuilding the plans table (SQLite)
        // drops its old parent table, which would cascade-empty the pivot.
        $links = DB::table('plan_services')
            ->selectRaw('plan_id, MIN(service_id) as service_id')
            ->groupBy('plan_id')
            ->orderBy('plan_id')
            ->get();

        Schema::table('plans', function (Blueprint $table): void {
            $table->foreignId('service_id')->nullable()->constrained()->cascadeOnDelete();
        });

        foreach ($links as $link) {
            DB::table('plans')
                ->where('id', $link->plan_id)
                ->update(['service_id' => $link->service_id]);
        }

        Schema::dropIfExists('plan_services');
    }
};
