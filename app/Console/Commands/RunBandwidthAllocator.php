<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Flow;
use App\Models\BandwidthLog;
use App\Services\MikrotikService;
use App\Services\ImportanceEngineService;

class RunBandwidthAllocator extends Command
{
    protected $signature = 'bandwidth:run';

    protected $description = 'Real-time bandwidth allocation engine';

    public function handle(
        MikrotikService $mikrotik,
        ImportanceEngineService $engine
    ) {
        $this->info('Bandwidth allocator started...');

        while (true) {
            $routerReachable = $mikrotik->isReachable();

            if (! $routerReachable) {
                $this->warn("MikroTik unreachable at {$mikrotik->connectionLabel()} — skipping until connected");
                sleep(5);
                continue;
            }

            $flows = Flow::with('user')
                ->where('is_active', true)
                ->get();

            if ($flows->isEmpty()) {
                sleep(5);
                continue;
            }

            $poolMbps = $mikrotik->measureIncomingBandwidthMbps();
            $availableBandwidth = $engine->formatLimit($poolMbps);

            $this->info("MikroTik connected — measured pool: {$availableBandwidth}");

            $scoredUsers = [];

            foreach ($flows as $flow) {
                $score = $engine->calculate(
                    $flow->user->role,
                    $flow->classification,
                    $flow->urgency_weight
                );

                $flow->importance_score = $score;
                $flow->save();

                $userId = $flow->user_id;

                if (! isset($scoredUsers[$userId]) || $score > $scoredUsers[$userId]['score']) {
                    $scoredUsers[$userId] = [
                        'flow' => $flow,
                        'score' => $score,
                    ];
                }
            }

            $totalScore = array_sum(array_column($scoredUsers, 'score'));

            foreach ($scoredUsers as $entry) {
                $flow = $entry['flow'];
                $score = $entry['score'];
                $shareMbps = $engine->allocateFromPool($score, $totalScore, $poolMbps);
                $bandwidth = $engine->formatLimit($shareMbps);

                $updated = $mikrotik->updateQueue(
                    $flow->user->name,
                    $flow->user->ip_address,
                    $bandwidth
                );

                if (! $updated) {
                    $this->warn("Queue update failed — skipping log for {$flow->user->name}");
                    continue;
                }

                BandwidthLog::create([
                    'user_id' => $flow->user->id,
                    'task_type' => $flow->classification,
                    'importance_score' => $score,
                    'allocated_bandwidth' => $bandwidth,
                    'available_bandwidth' => $availableBandwidth,
                    'router_connected' => true,
                ]);

                $this->info("{$flow->user->name} → {$bandwidth} (score {$score})");
            }

            sleep(5);
        }
    }
}
