<?php

namespace Database\Seeders;

use App\Models\Flow;
use App\Models\User;
use App\Services\ImportanceEngineService;
use Illuminate\Database\Seeder;

class FlowSeeder extends Seeder
{
    public function run(): void
    {
        if (Flow::exists()) {
            return;
        }

        $engine = app(ImportanceEngineService::class);
        $samples = [
            ['role' => 'dean', 'classification' => 'REAL_TIME', 'destination' => 'zoom.us:443'],
            ['role' => 'lecturer', 'classification' => 'STREAMING', 'destination' => 'youtube.com:443'],
        ];

        foreach ($samples as $sample) {
            $user = User::where('role', $sample['role'])->first();
            if (!$user) {
                continue;
            }

            $urgency = $engine->urgencyFor($sample['classification']);
            $score = $engine->calculate($user->role, $sample['classification'], $urgency);

            Flow::create([
                'user_id' => $user->id,
                'task_type' => $sample['classification'],
                'priority' => $engine->taskPriority($sample['classification']),
                'is_active' => true,
                'source_ip' => $user->ip_address,
                'destination' => $sample['destination'],
                'classification' => $sample['classification'],
                'bytes' => rand(100000, 50000000),
                'urgency_weight' => $urgency,
                'importance_score' => $score,
            ]);
        }
    }
}
