<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->string('currency', 3)->nullable()->after('phone');
            $table->string('email')->nullable()->after('currency');
            $table->string('logo')->nullable()->after('email');
            $table->date('financial_year_start')->nullable()->after('logo');
            $table->date('financial_year_end')->nullable()->after('financial_year_start');
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn(['financial_year_end', 'financial_year_start', 'logo', 'email', 'currency']);
        });
    }
};
