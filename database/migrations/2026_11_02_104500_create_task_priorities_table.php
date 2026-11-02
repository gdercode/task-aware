<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_priorities', function (Blueprint $table) {
            $table->id();
            $table->string('slug')->unique();
            $table->string('name');
            $table->unsignedTinyInteger('weight');
            $table->unsignedTinyInteger('urgency');
            $table->boolean('is_critical')->default(false);
            $table->text('match_terms')->nullable();
            $table->unsignedTinyInteger('sort_order')->default(0);
            $table->timestamps();
        });

        $now = now();

        foreach ([
            ['slug' => 'REAL_TIME', 'name' => 'Video conferencing', 'weight' => 8, 'urgency' => 5, 'is_critical' => true, 'match_terms' => 'zoom, teams, meet.google, meet, webex, skype, jitsi, bigbluebutton, whereby', 'sort_order' => 1],
            ['slug' => 'LEARNING', 'name' => 'Online learning', 'weight' => 7, 'urgency' => 4, 'is_critical' => true, 'match_terms' => 'moodle, canvas, blackboard, classroom.google, schoology, edx, coursera', 'sort_order' => 2],
            ['slug' => 'COLLABORATION', 'name' => 'Digital collaboration', 'weight' => 5, 'urgency' => 3, 'is_critical' => false, 'match_terms' => 'docs.google, drive.google, sharepoint, office.com, office365, slack, notion, onedrive, dropbox', 'sort_order' => 3],
            ['slug' => 'DATA_TRANSFER', 'name' => 'Data transfer', 'weight' => 4, 'urgency' => 3, 'is_critical' => false, 'match_terms' => null, 'sort_order' => 4],
            ['slug' => 'STREAMING', 'name' => 'Streaming', 'weight' => 2, 'urgency' => 2, 'is_critical' => false, 'match_terms' => 'youtube, netflix, tiktok, twitch, spotify, vimeo', 'sort_order' => 5],
            ['slug' => 'NORMAL', 'name' => 'General use', 'weight' => 2, 'urgency' => 2, 'is_critical' => false, 'match_terms' => null, 'sort_order' => 6],
            ['slug' => 'BULK', 'name' => 'Bulk download', 'weight' => 1, 'urgency' => 1, 'is_critical' => false, 'match_terms' => null, 'sort_order' => 7],
        ] as $task) {
            DB::table('task_priorities')->insert($task + [
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('task_priorities');
    }
};
