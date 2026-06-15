<?php

namespace App\Services;

use App\Models\Flow;
use Illuminate\Support\Collection;

class AllocationPreviewService
{
    public function __construct(
        protected ImportanceEngineService $engine
    ) {}

    public function forActiveFlows(int $poolMbps): Collection
    {
        if ($poolMbps <= 0) {
            return collect();
        }

        $flows = Flow::with('user')
            ->where('is_active', true)
            ->get();

        $scoredUsers = [];

        foreach ($flows as $flow) {
            $score = $this->engine->calculate(
                $flow->user->role,
                $flow->classification ?? $flow->task_type,
                $flow->urgency_weight ?? 1
            );

            $userId = $flow->user_id;

            if (! isset($scoredUsers[$userId]) || $score > $scoredUsers[$userId]['score']) {
                $scoredUsers[$userId] = [
                    'user' => $flow->user,
                    'score' => $score,
                ];
            }
        }

        if ($scoredUsers === []) {
            return collect();
        }

        $totalScore = array_sum(array_column($scoredUsers, 'score'));
        $rows = collect();

        foreach ($scoredUsers as $entry) {
            $shareMbps = $this->engine->allocateFromPool($entry['score'], $totalScore, $poolMbps);

            $rows->push((object) [
                'user' => $entry['user'],
                'score' => $entry['score'],
                'bandwidth' => $this->engine->formatLimit($shareMbps),
                'last_seen_at' => now(),
            ]);
        }

        return $rows->sortBy('user.name')->values();
    }
}
