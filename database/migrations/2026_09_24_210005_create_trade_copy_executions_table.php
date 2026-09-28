<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
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

    public function down(): void
    {
        Schema::dropIfExists('trade_copy_executions');
    }
};
