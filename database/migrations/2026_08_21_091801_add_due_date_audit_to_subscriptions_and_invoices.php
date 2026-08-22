<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            if (! Schema::hasColumn('invoices', 'previous_due_date')) {
                $table->date('previous_due_date')->nullable()->after('due_date');
            }
            if (! Schema::hasColumn('invoices', 'due_date_changed_by')) {
                $table->foreignId('due_date_changed_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            if (! Schema::hasColumn('subscriptions', 'due_date_change_count')) {
                $table->unsignedInteger('due_date_change_count')->default(0)->after('status');
            }
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropForeign(['due_date_changed_by']);
            $table->dropColumn(['previous_due_date', 'due_date_changed_by']);
        });

        Schema::table('subscriptions', function (Blueprint $table) {
            $table->dropColumn('due_date_change_count');
        });
    }
};
