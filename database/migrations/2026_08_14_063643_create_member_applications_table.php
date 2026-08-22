<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('member_applications', function (Blueprint $table) {
            $table->id();
            $table->string('identifier_type'); // contact | government_id | code
            $table->string('identifier_value');
            $table->json('payload');
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->foreignId('created_member_id')->nullable()->constrained('members')->nullOnDelete();
            $table->timestamps();

            $table->index(['identifier_type', 'identifier_value']);
            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_applications');
    }
};
