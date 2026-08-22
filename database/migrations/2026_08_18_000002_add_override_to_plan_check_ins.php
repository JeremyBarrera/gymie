<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plan_check_ins', function (Blueprint $table) {
            $table->boolean('override')->default(false)->after('service_id');
            $table->foreignId('override_by_user_id')->nullable()->after('override')
                ->constrained('users')->nullOnDelete();
            $table->string('override_reason')->nullable()->after('override_by_user_id');
        });
    }

    public function down(): void
    {
        Schema::table('plan_check_ins', function (Blueprint $table) {
            $table->dropConstrainedForeignId('override_by_user_id');
            $table->dropColumn(['override', 'override_reason']);
        });
    }
};
