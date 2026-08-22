<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_check_ins', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable()->change();
            $table->foreignId('plan_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('plan_check_ins', function (Blueprint $table) {
            $table->foreignId('subscription_id')->nullable(false)->change();
            $table->foreignId('plan_id')->nullable(false)->change();
        });
    }
};
