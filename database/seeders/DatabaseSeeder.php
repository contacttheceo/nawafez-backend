<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create the super admin
        User::firstOrCreate(
            ['email' => 'admin@nawafez.com'],
            [
                'name_ar'           => 'مدير نوافذ',
                'name_en'           => 'Nawafez Admin',
                'password'          => Hash::make(env('ADMIN_PASSWORD', 'Nawafez@Admin2025')),
                'role'              => 'admin',
                'email_verified_at' => now(),
            ]
        );
    }
}
