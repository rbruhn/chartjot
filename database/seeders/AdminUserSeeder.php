<?php

namespace Database\Seeders;

use App\Enums\UserStatus;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class AdminUserSeeder extends Seeder
{
    public function run(): void
    {
        if (User::where('is_admin', true)->exists()) {
            return;
        }

        User::forceCreate([
            'name'     => env('ADMIN_NAME', 'Admin'),
            'email'    => env('ADMIN_EMAIL', 'admin@chartjot.local'),
            'password' => Hash::make(env('ADMIN_PASSWORD', 'change-me-immediately!')),
            'status'   => UserStatus::Active,
            'is_admin' => true,
        ]);
    }
}
