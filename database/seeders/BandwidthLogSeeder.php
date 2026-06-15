<?php

namespace Database\Seeders;

use App\Models\BandwidthLog;
use App\Models\User;
use App\Services\ImportanceEngineService;
use Illuminate\Database\Seeder;

class BandwidthLogSeeder extends Seeder
{
    public function run(): void
    {
        if (BandwidthLog::exists()) {
            return;
        }

        $engine = app(ImportanceEngineService::class);
        $taskTypes = ['REAL_TIME', 'STREAMING', 'DATA_TRANSFER', 'BULK', 'NORMAL'];
        $users = User::all();

        if ($users->isEmpty()) {
            return;
        }

        foreach (range(1, 30) as $i) {
            $user = $users->random();
            $taskType = $taskTypes[array_rand($taskTypes)];
            $score = $engine->calculate($user->role, $taskType, rand(0, 2));

            BandwidthLog::create([
                'user_id' => $user->id,
                'task_type' => $taskType,
                'importance_score' => $score,
                'allocated_bandwidth' => $engine->bandwidthFromScore($score),
                'created_at' => now()->subMinutes(30 - $i),
                'updated_at' => now()->subMinutes(30 - $i),
            ]);
        }
    }
}
