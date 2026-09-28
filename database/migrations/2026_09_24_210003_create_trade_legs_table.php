<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_legs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('sequence');
            $table->boolean('runner')->default(false);
            $table->string('exit_order_id');
            $table->string('order_name');
            $table->string('reason');
            $table->unsignedSmallInteger('quantity');
            $table->datetime('exited_at');
            $table->decimal('average_exit_price', 12, 4);
            $table->decimal('points', 10, 4);
            $table->decimal('gross_pnl', 12, 2);
            $table->decimal('mae_points', 10, 4)->nullable();
            $table->decimal('mfe_points', 10, 4)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_legs');
    }
};
