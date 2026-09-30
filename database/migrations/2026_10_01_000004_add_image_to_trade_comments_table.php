<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_comments', function (Blueprint $table) {
            // Optional image attached to a comment or reply. Stored on the
            // private disk under a server-generated name; served only through
            // trades.shared.comment-image, which re-checks access.
            $table->string('image_disk')->nullable()->after('body');
            $table->string('image_path')->nullable()->after('image_disk');
            $table->string('image_mime_type')->nullable()->after('image_path');
        });
    }

    public function down(): void
    {
        Schema::table('trade_comments', function (Blueprint $table) {
            $table->dropColumn(['image_disk', 'image_path', 'image_mime_type']);
        });
    }
};
