<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use App\Models\User;
use Illuminate\Support\Facades\Hash;


class UserSeeder extends Seeder
{
    public function run()
    {
        User::create([
            'name' => 'Dean User',
            'email' => 'dean@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'dean',
            'ip_address' => '192.168.56.20',
        ]);

        User::create([
            'name' => 'Lecturer User',
            'email' => 'lecturer@gmail.com',
            'password' => Hash::make('password'),
            'role' => 'lecturer',
            'ip_address' => '192.168.56.21',
        ]);

        User::create([
            'name' => 'Student User',
            'email' => 'student@example.com',
            'password' => Hash::make('password'),
            'role' => 'student',
            'ip_address' => '192.168.56.22',
        ]);
    }
}
