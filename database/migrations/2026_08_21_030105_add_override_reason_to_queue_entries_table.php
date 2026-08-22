<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->text('override_reason')->nullable()->after('override_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('queue_entries', function (Blueprint $table) {
            $table->dropColumn('override_reason');
        });
    }
};
