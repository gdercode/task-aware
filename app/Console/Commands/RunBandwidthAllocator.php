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

        $this->info("Bandwidth allocator started...");

        while (true) {

            $flows = Flow::with('user')
                ->where('is_active', true)
                ->get();

            foreach ($flows as $flow) {

                $score = $engine->calculate(
                    $flow->user->role,
                    $flow->classification,
                    $flow->urgency_weight
                );

                $bandwidth = $engine->bandwidthFromScore($score);

                // Update MikroTik queue
                $mikrotik->updateQueue(
                    $flow->user->name,
                    $flow->user->ip_address,
                    $bandwidth
                );

                // Save allocation
                BandwidthLog::create([
                    'user_id' => $flow->user->id,
                    'task_type' => $flow->classification,
                    'importance_score' => $score,
                    'allocated_bandwidth' => $bandwidth,
                ]);

                $this->info(
                    "{$flow->user->name} → {$bandwidth}"
                );
            }

            sleep(5);
        }
    }
}
