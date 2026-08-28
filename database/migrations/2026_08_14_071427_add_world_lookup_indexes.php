<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    public function up(): void
    {
        Schema::table('states', function (Blueprint $table): void {
            $table->index(['country_id', 'name'], 'states_country_name_index');
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->index(['state_id', 'name'], 'cities_state_name_index');
        });
    }

    

    public function down(): void
    {
        Schema::table('states', function (Blueprint $table): void {
            $table->dropIndex('states_country_name_index');
        });

        Schema::table('cities', function (Blueprint $table): void {
            $table->dropIndex('cities_state_name_index');
        });
    }
};
