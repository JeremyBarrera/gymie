<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('queue_entries', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->string('kind'); // checkin | signup
            $table->json('payload');
            $table->string('identifier_type')->nullable(); // contact | government_id | code
            $table->string('status')->default('waiting'); // waiting | attending | approved | denied | expired
            $table->foreignId('claimed_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('claimed_at')->nullable();
            $table->boolean('override')->default(false);
            $table->foreignId('override_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->text('denied_reason')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();

            $table->index(['location_id', 'status', 'created_at']);
            $table->index(['claimed_by_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('queue_entries');
    }
};
