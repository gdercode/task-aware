<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedSmallInteger('weight');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['slug' => 'dean', 'name' => 'Dean', 'weight' => 10, 'is_default' => false],
            ['slug' => 'lecturer', 'name' => 'Lecturer', 'weight' => 7, 'is_default' => false],
            ['slug' => 'student', 'name' => 'Student', 'weight' => 4, 'is_default' => true],
        ] as $role) {
            DB::table('roles')->updateOrInsert(
                ['slug' => $role['slug']],
                $role + ['created_at' => $now, 'updated_at' => $now],
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('roles');
    }
};
