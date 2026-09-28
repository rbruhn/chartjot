<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Reclassify any 'live' records left by the original migration default
        DB::table('accounts')->where('account_type', 'live')->update(['account_type' => 'funded']);

        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_type')->default('funded')->change();
        });
    }

    public function down(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('account_type')->default('live')->change();
        });
    }
};
