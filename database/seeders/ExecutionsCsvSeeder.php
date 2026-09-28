<?php

namespace Database\Seeders;

use App\Models\Journal;
use App\Models\User;
use App\Services\ExecutionsCsvImporter;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class ExecutionsCsvSeeder extends Seeder
{
    public function run(ExecutionsCsvImporter $importer): void
    {
        $csvPath = database_path('seeders/data/NinjaTrader Grid 2026-09-27 03-06 PM.csv');

        if (! file_exists($csvPath)) {
            $this->command->warn('Executions CSV not found — skipping trade seed.');
            return;
        }

        $seedEmail = env('SEED_USER_EMAIL');

        $user = $seedEmail
            ? User::where('email', $seedEmail)->first()
            : User::where('is_admin', false)->oldest()->first();

        if (! $user) {
            $this->command->warn('No non-admin user found — skipping trade seed. Register an account first, or set SEED_USER_EMAIL in .env.');
            return;
        }

        $journal = Journal::firstOrCreate(
            ['user_id' => $user->id],
            [
                'name'              => "{$user->name}'s Trade Journal",
                'timezone'          => 'America/New_York',
                'ingest_token_hash' => Hash::make('dev-token'),
            ]
        );

        $result = $importer->import($journal, $csvPath);

        $this->command->info(sprintf(
            'CSV seed: %d trades imported, %d skipped, %d errors.',
            $result['trades_created'],
            $result['trades_skipped'],
            count($result['errors'])
        ));

        foreach ($result['errors'] as $err) {
            $this->command->warn('  ' . $err);
        }
    }
}
