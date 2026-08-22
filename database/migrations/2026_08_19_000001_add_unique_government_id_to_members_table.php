<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Re-add a uniqueness guarantee on the government ID. The previous plain
     * unique index was dropped in 2026_08_18_000001 because it rejected
     * members that merely shared an email or ID with different identifiers —
     * the government ID is a person-level identity instead, so it alone
     * stays unique.
     *
     * NULLs are distinct in MySQL and SQLite, so members without a
     * government ID are unaffected, while any two members carrying the same
     * non-null ID conflict — including soft-deleted records (a deleted
     * record still holds the person's ID, so re-creating or restoring
     * around it is deliberately refused).
     *
     * The application already rejects duplicates through the location-aware
     * UI and API rules; this index is the race-condition guarantee that two
     * concurrent creations can never both succeed.
     *
     * Before adding the index the migration aborts if duplicate government
     * IDs exist — they must be cleaned up with
     * `php artisan members:merge-duplicates` first (and any soft-deleted
     * duplicate records permanently deleted from the trash).
     */
    public function up(): void
    {
        $duplicate = DB::table('members')
            ->whereNotNull('government_id')
            ->groupBy('government_id')
            ->havingRaw('COUNT(*) > 1')
            ->value('government_id');

        if ($duplicate !== null) {
            throw new \RuntimeException(
                "Duplicate members with government_id '{$duplicate}' exist (including soft-deleted records). "
                . 'Run `php artisan members:merge-duplicates` to merge live duplicates and permanently delete '
                . 'any soft-deleted duplicates from the trash before migrating.'
            );
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->unique('government_id', 'members_government_id_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropUnique('members_government_id_unique');
        });
    }
};