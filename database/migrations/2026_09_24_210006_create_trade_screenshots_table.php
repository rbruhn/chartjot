<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->string('disk');
            $table->string('path');
            $table->string('caption')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('bytes');
            $table->datetime('captured_at')->nullable();
            $table->string('source')->default('nt8');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_screenshots');
    }
};
