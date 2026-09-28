<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_copies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('status');
            $table->string('instrument_contract')->nullable();
            $table->string('instrument_symbol')->nullable();
            $table->decimal('instrument_tick_size', 10, 6)->nullable();
            $table->decimal('instrument_point_value', 10, 2)->nullable();
            $table->string('expected_contract_size')->nullable();
            $table->decimal('expected_multiplier', 10, 4)->nullable();
            $table->boolean('expected_faded')->nullable();
            $table->boolean('expected_blown')->nullable();
            $table->unsignedSmallInteger('expected_quantity')->nullable();
            $table->json('warnings');
            $table->string('direction')->nullable();
            $table->unsignedSmallInteger('quantity')->default(0);
            $table->decimal('entry_average_price', 12, 4)->nullable();
            $table->decimal('exit_average_price', 12, 4)->nullable();
            $table->datetime('entered_at')->nullable();
            $table->datetime('exited_at')->nullable();
            $table->decimal('points', 10, 4)->nullable();
            $table->integer('ticks')->nullable();
            $table->decimal('gross_pnl', 12, 2)->nullable();
            $table->decimal('commission', 10, 2)->nullable();
            $table->decimal('fees', 10, 2)->nullable();
            $table->decimal('net_pnl', 12, 2)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_copies');
    }
};
