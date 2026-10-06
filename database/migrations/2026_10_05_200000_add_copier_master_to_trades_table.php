<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            // #79: a copier follower's master. The AddOn sends the master's trade_id, kept as sent so a follower
            // that arrives first can be linked once its master does; master_trade_id is that link, within the
            // same journal only. Deleting a master leaves its followers as normal trades.
            $table->string('copier_master_source_trade_id')->nullable()->after('source_trade_id');
            $table->foreignId('master_trade_id')->nullable()->after('copier_master_source_trade_id')
                ->constrained('trades')->nullOnDelete();
            $table->index(['journal_id', 'copier_master_source_trade_id']);
        });
    }

    public function down(): void
    {
        Schema::table('trades', function (Blueprint $table) {
            $table->dropIndex(['journal_id', 'copier_master_source_trade_id']);
            $table->dropConstrainedForeignId('master_trade_id');
            $table->dropColumn('copier_master_source_trade_id');
        });
    }
};
