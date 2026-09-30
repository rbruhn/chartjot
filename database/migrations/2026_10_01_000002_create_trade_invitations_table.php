<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('invited_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('invited_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->string('status');
            $table->timestamps();

            // One row per trade per invitee; re-inviting after a decline or
            // revoke resets this row to pending.
            $table->unique(['trade_id', 'invited_user_id']);
            $table->index(['invited_user_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_invitations');
    }
};
