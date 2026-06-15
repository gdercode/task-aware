<?php

namespace App\Services;

use App\Models\Flow;
use Illuminate\Support\Collection;

class AllocationPreviewService
{
    public function __construct(
        protected ImportanceEngineService $engine
    ) {}

    /**
     * @return array{pool_kbps: int, pool_label: string, total_score: int, users: Collection}
     */
    public function build(int $poolKbps): array
    {
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

        if ($scoredUsers === [] || $poolKbps <= 0) {
            return [
                'pool_kbps' => $poolKbps,
                'pool_label' => $this->engine->formatLimit(max($poolKbps, 0)),
                'total_score' => 0,
                'users' => collect(),
            ];
        }

        $totalScore = array_sum(array_column($scoredUsers, 'score'));
        $scores = collect($scoredUsers)->mapWithKeys(fn ($entry, $userId) => [$userId => $entry['score']])->all();
        $distribution = $this->engine->distributePool($scores, $poolKbps);
        $rows = collect();

        foreach ($scoredUsers as $userId => $entry) {
            $shareKbps = $distribution[$userId] ?? 0;
            $sharePercent = $totalScore > 0
                ? round(($entry['score'] / $totalScore) * 100, 1)
                : 0;

            $rows->push((object) [
                'user' => $entry['user'],
                'score' => $entry['score'],
                'share_percent' => $sharePercent,
                'bandwidth' => $shareKbps > 0 ? $this->engine->formatLimit($shareKbps) : '0k/0k',
                'share_kbps' => $shareKbps,
                'last_seen_at' => now(),
            ]);
        }

        return [
            'pool_kbps' => $poolKbps,
            'pool_label' => $this->engine->formatLimit($poolKbps),
            'total_score' => $totalScore,
            'users' => $rows->sortByDesc('score')->values(),
        ];
    }

    public function forActiveFlows(int $poolKbps): Collection
    {
        return $this->build($poolKbps)['users']
            ->filter(fn ($row) => $row->share_kbps > 0)
            ->values();
    }
}
