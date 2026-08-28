<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->enum('status', ['active', 'inactive', 'pending', 'banned'])->default('active')->nullable()->change();
            $table->string('ban_reason')->nullable()->after('status');
        });
    }

    

    public function down(): void
    {
        $irreversible = DB::table('members')
            ->whereIn('status', ['banned', 'pending'])
            ->count();

        if ($irreversible > 0) {
            throw new RuntimeException("Cannot revert: {$irreversible} member(s) hold a banned or pending status. Resolve them first.");
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->dropColumn('ban_reason');
            $table->enum('status', ['active', 'inactive'])->default('active')->nullable()->change();
        });
    }
};
