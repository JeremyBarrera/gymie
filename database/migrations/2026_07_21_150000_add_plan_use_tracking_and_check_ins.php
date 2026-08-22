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
        Schema::table('plans', function (Blueprint $table) {
            $table->boolean('track_uses')->default(false)->after('status');
            $table->unsignedInteger('uses_limit')->nullable()->after('track_uses');
        });

        Schema::create('plan_check_ins', function (Blueprint $table) {
            $table->id();
            $table->foreignId('member_id')->constrained('members')->cascadeOnDelete();
            $table->foreignId('subscription_id')->constrained('subscriptions')->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained('plans')->cascadeOnDelete();
            $table->foreignId('service_id')->constrained('services')->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('checked_in_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('checked_in_at')->index();
            $table->timestamps();

            $table->index(['subscription_id', 'checked_in_at']);
            $table->index(['member_id', 'checked_in_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('plan_check_ins');

        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn(['track_uses', 'uses_limit']);
        });
    }
};
