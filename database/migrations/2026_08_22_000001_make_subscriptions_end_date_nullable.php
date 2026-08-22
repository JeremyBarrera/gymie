<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            // Evergreen subscriptions (plans without a duration) never end:
            // their end_date stays null for the whole lifetime.
            $table->date('end_date')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('subscriptions', function (Blueprint $table): void {
            $table->date('end_date')->nullable(false)->change();
        });
    }
};
