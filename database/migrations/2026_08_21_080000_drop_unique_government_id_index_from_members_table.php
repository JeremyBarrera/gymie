<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Government IDs are no longer a person-level unique identity: members may
 * share a government ID (and phone number) as long as the name differs, so
 * the hard unique index added in 2026_08_19_000001 is dropped. Duplicate
 * signups are rejected only by the application-level rule — name + contact
 * + government ID must ALL match an existing member.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->dropUnique('members_government_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->unique('government_id', 'members_government_id_unique');
        });
    }
};
