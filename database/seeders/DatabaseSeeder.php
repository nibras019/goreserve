<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Create roles
        Role::create(['name' => 'admin']);
        Role::create(['name' => 'vendor']);
        Role::create(['name' => 'customer']);

        // Create admin user
        $admin = \App\Models\User::create([
            'name' => 'Admin User',
            'email' => 'admin@goreserve.com',
            'password' => bcrypt('password'),
            'email_verified_at' => now(),
        ]);
        $admin->assignRole('admin');

        // Run other seeders
        $this->call([
            BusinessSeeder::class,
            ServiceSeeder::class,
            StaffSeeder::class,
        ]);
    }
}