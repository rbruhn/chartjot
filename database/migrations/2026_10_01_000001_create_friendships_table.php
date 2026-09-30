<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('friendships', function (Blueprint $table) {
            $table->id();
            // Who sent the request, and who received it.
            $table->foreignId('requester_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('users')->cascadeOnDelete();
            // The same pair, normalized smaller-id-first, set by the model on
            // every save. The unique index on these makes A→B and B→A the same
            // row at the database level, so concurrent requests in both
            // directions can't create two friendships.
            $table->unsignedBigInteger('user_low_id');
            $table->unsignedBigInteger('user_high_id');
            $table->string('status');
            $table->timestamps();

            $table->unique(['user_low_id', 'user_high_id']);
            $table->index(['recipient_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('friendships');
    }
};
