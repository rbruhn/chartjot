<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->text('body');
            $table->string('phase');
            $table->datetime('occurred_at');
            $table->timestamps();

            $table->index(['trade_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_notes');
    }
};
