<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Drop the member's stored location.
     *
     * A member's location is derived from the plan of its subscriptions (the
     * "jurisdiction" the plan grants) and is never assigned manually, so the
     * column and its foreign key are removed.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->foreignId('location_id')
                ->nullable()
                ->after('status')
                ->constrained('locations')
                ->nullOnDelete();
        });
    }
};