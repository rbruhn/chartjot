<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trades', function (Blueprint $table) {
            $table->id();
            $table->ulid('uuid')->unique();
            $table->foreignId('journal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->string('source_trade_id');
            $table->string('source')->default('ninjatrader_8');
            $table->string('addon_version')->nullable();
            $table->string('trade_type');
            $table->string('trade_type_other')->nullable();
            $table->string('instrument');
            $table->string('instrument_symbol');
            $table->decimal('tick_size', 10, 6);
            $table->decimal('point_value', 10, 2);
            $table->string('direction');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('total_entry_quantity');
            $table->datetime('entry_at');
            $table->datetime('exit_at');
            $table->decimal('entry_price', 12, 4);
            $table->decimal('exit_price', 12, 4);
            $table->string('entry_order_name');
            $table->string('exit_order_name');
            $table->string('exit_reason');
            $table->decimal('points', 10, 4);
            $table->integer('ticks');
            $table->decimal('gross_pnl', 12, 2);
            $table->decimal('commission', 12, 2);
            $table->decimal('fees', 12, 2)->nullable();
            $table->decimal('net_pnl', 12, 2);
            $table->decimal('excursion_mae_points', 10, 4)->nullable();
            $table->decimal('excursion_mfe_points', 10, 4)->nullable();
            $table->decimal('excursion_max_adverse_price', 12, 4)->nullable();
            $table->decimal('excursion_max_favorable_price', 12, 4)->nullable();
            $table->boolean('excursion_complete')->default(true);
            $table->string('copies_source')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique(['journal_id', 'source_trade_id']);
            $table->index(['account_id', 'entry_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trades');
    }
};
