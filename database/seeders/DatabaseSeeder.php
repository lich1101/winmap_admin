<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        User::updateOrCreate(
            ['email' => env('ADMIN_EMAIL', 'admin@winmap.local')],
            [
                'name' => env('ADMIN_NAME', 'Administrator'),
                'password' => Hash::make(env('ADMIN_PASSWORD', 'change-this-password')),
                'role' => 'administrator',
                'is_active' => true,
                'email_verified_at' => now(),
            ],
        );

        if (app()->environment('local')) {
            User::factory()->create([
                'name' => 'Viewer Demo',
                'email' => 'viewer@example.com',
                'role' => 'viewer',
            ]);
        }
    }
}
