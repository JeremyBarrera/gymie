<?php

use App\Support\BlindIndex;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Encrypt `government_id` at rest and add a deterministic blind-index
     * hash column so the duplicate check, lookups and searches keep working
     * (the `encrypted` cast uses a random nonce, making ciphertext equality
     * impossible). Existing rows are encrypted in place; every row gets its
     * hash computed from the plaintext before it is replaced.
     */
    public function up(): void
    {
        Schema::table('members', function (Blueprint $table): void {
            $table->char('government_id_hash', 64)->nullable()->index()->after('government_id');
            $table->text('government_id')->nullable()->change();
        });

        $chunk = DB::table('members')
            ->whereNotNull('government_id')
            ->orderBy('id')
            ->select('id', 'government_id');

        foreach ($chunk->cursor() as $member) {
            $plain = (string) $member->government_id;

            if (! str_starts_with($plain, 'eyJ')) {
                DB::table('members')->where('id', $member->id)->update([
                    'government_id' => Crypt::encryptString($plain),
                    'government_id_hash' => BlindIndex::compute($plain),
                ]);
            }
        }
    }

    public function down(): void
    {
        $rows = DB::table('members')
            ->whereNotNull('government_id')
            ->orderBy('id')
            ->select('id', 'government_id')
            ->get();

        foreach ($rows as $member) {
            $cipher = (string) $member->government_id;

            if (str_starts_with($cipher, 'eyJ')) {
                DB::table('members')->where('id', $member->id)->update([
                    'government_id' => Crypt::decryptString($cipher),
                ]);
            }
        }

        Schema::table('members', function (Blueprint $table): void {
            $table->dropIndex(['government_id_hash']);
            $table->dropColumn('government_id_hash');
            $table->string('government_id')->nullable()->change();
        });
    }
};
