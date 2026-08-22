<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('tokenable_type'); // Location::class | Service::class
            $table->unsignedBigInteger('tokenable_id');
            $table->string('kind'); // checkin | signup
            $table->timestamps();

            $table->index(['tokenable_type', 'tokenable_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('location_tokens');
    }
};
