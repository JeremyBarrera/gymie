<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    

    public function up(): void
    {
        $duplicate = DB::table('members')
            ->whereNotNull('government_id')
            ->groupBy('government_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('government_id');

        if ($duplicate !== null) {
            throw new RuntimeException(
                "Duplicate members with government_id '{$duplicate}' exist (including soft-deleted records). "
                .'Run `php artisan members:merge-duplicates` to merge live duplicates and permanently delete '
                .'any soft-deleted duplicates from the trash before migrating.'
            );
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->unique('government_id', 'members_government_id_unique');
        });
    }

    

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropUnique('members_government_id_unique');
        });
    }
};
