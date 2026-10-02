<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            // Optional stop price sent by the AddOn. Nullable because a trade
            // may have no stop, and older AddOns never send it.
            $table->decimal('stop_price', 12, 4)->nullable()->after('exit_price');
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropColumn('stop_price');
        });
    }
};
