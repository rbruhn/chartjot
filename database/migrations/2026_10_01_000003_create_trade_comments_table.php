<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trade_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trade_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // Replies point at a top-level comment only (one level of
            // nesting); enforced by validation when a comment is posted.
            $table->foreignId('parent_comment_id')->nullable()->constrained('trade_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();

            $table->index(['trade_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trade_comments');
    }
};
