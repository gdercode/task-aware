<?php

namespace Database\Seeders;

use App\Models\BandwidthLog;
use App\Models\User;
use App\Services\ImportanceEngineService;
use App\Services\MikrotikService;
use Illuminate\Database\Seeder;

class BandwidthLogSeeder extends Seeder
{
    public function run(): void
    {
        if (BandwidthLog::exists()) {
            return;
        }

        $engine = app(ImportanceEngineService::class);
        $monitor = app(MikrotikService::class);
        $taskTypes = ['REAL_TIME', 'STREAMING', 'DATA_TRANSFER', 'BULK', 'NORMAL'];
        $users = User::all();

        if ($users->isEmpty() || ! $monitor->isReachable()) {
            return;
        }

        $poolMbps = $monitor->measureIncomingBandwidthMbps();
        $availableBandwidth = $engine->formatLimit($poolMbps);

        $scores = [];
        foreach ($users as $user) {
            $taskType = $taskTypes[array_rand($taskTypes)];
            $scores[$user->id] = [
                'task_type' => $taskType,
                'score' => $engine->calculate($user->role, $taskType, rand(0, 2)),
            ];
        }

        $scoreValues = collect($scores)->mapWithKeys(fn ($entry, $id) => [$id => $entry['score']])->all();
        $distribution = $engine->distributePool($scoreValues, max($poolMbps, 1));

        foreach (range(1, 30) as $i) {
            $user = $users->random();
            $entry = $scores[$user->id];
            $shareMbps = $distribution[$user->id] ?? 0;

            BandwidthLog::create([
                'user_id' => $user->id,
                'task_type' => $entry['task_type'],
                'importance_score' => $entry['score'],
                'allocated_bandwidth' => $engine->formatLimit($shareMbps),
                'available_bandwidth' => $availableBandwidth,
                'router_connected' => false,
                'created_at' => now()->subMinutes(30 - $i),
                'updated_at' => now()->subMinutes(30 - $i),
            ]);
        }
    }
}
