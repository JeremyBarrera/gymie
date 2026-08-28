<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    public function up(): void
    {
        Schema::table('members', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });
    }

    

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
