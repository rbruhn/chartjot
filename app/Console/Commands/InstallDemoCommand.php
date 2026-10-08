<?php

namespace App\Console\Commands;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\JournalWiper;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

#[Signature('demo:install
    {file : A journal zip from journal:export to show in the demo}')]
#[Description('Create the read-only demo account, or reset it, and load a journal into it')]
class InstallDemoCommand extends Command
{
    public const EMAIL = 'demo@chartjot.local';

    public function handle(JournalWiper $wiper): int
    {
        if (! is_file($this->argument('file'))) {
            $this->error('That file doesn\'t exist.');

            return self::FAILURE;
        }

        $demo = User::with('journal')->where('is_demo', true)->first()
            ?? User::create([
                'name'     => 'Demo Trader',
                'email'    => self::EMAIL,
                'password' => Str::random(64),
                'status'   => UserStatus::Active,
                'is_demo'  => true,
            ])->load('journal');

        // Running it again resets the demo to exactly what's in the file.
        $wiper->wipe($demo->journal);
        $demo->journal->update(['name' => 'Demo Trade Journal', 'timezone' => null]);

        $this->info("Demo account: {$demo->email}");

        return $this->call('journal:import', [
            'file'    => $this->argument('file'),
            '--email' => $demo->email,
        ]);
    }
}
