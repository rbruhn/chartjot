<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('failed_trade_imports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $table->string('account_name');
            $table->string('source_trade_id')->nullable();
            $table->string('reason');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['journal_id', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('failed_trade_imports');
    }
};
