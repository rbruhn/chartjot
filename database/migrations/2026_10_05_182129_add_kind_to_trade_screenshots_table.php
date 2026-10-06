<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trade_screenshots', function (Blueprint $table) {
            // Which chart image this is (#72): the exit image every existing row
            // and manual upload already is, or the AddOn's entry image.
            $table->string('kind')->default('exit')->after('trade_id');
        });
    }

    public function down(): void
    {
        Schema::table('trade_screenshots', function (Blueprint $table) {
            $table->dropColumn('kind');
        });
    }
};
