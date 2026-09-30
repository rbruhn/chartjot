<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Copier reconciliation (#27) is gone: NinjaTrader's own copier dashboard already shows missed copies and
 * still-open followers live, which is the only time they're actionable. Each account's trades are now
 * recorded independently (#28), so the per-follower copy rows and the master's copies_source are dropped.
 * These tables and the column never held rows in production.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('trade_copy_executions');
        Schema::dropIfExists('trade_copies');

        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('copies_source');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->string('copies_source')->nullable()->after('excursion_complete');
        });

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

        Schema::create('trade_copy_executions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_copy_id')->constrained()->cascadeOnDelete();
            $table->string('source_execution_id');
            $table->string('order_id');
            $table->datetime('occurred_at');
            $table->string('action');
            $table->string('role');
            $table->unsignedSmallInteger('quantity');
            $table->unsignedSmallInteger('allocated_quantity');
            $table->decimal('price', 12, 4);
            $table->decimal('commission', 10, 2);
            $table->decimal('fee', 10, 2)->nullable();
            $table->string('order_name');
            $table->integer('position_after');
            $table->timestamps();

            $table->unique(['trade_copy_id', 'source_execution_id']);
        });
    }
};
