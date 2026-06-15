<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Support\Collection;

class AllocationPreviewService
{
    public function __construct(
        protected ImportanceEngineService $engine,
        protected MikrotikService $mikrotik,
    ) {}

    /**
     * @param  array<string, true>  $onlineIps
     * @return array{
     *     pool_kbps: int,
     *     pool_label: string,
     *     pool_display: string,
     *     total_score: int,
     *     online_count: int,
     *     offline_count: int,
     *     users: Collection
     * }
     */
    public function build(int $poolKbps, array $onlineIps = []): array
    {
        $flows = Flow::with('user')
            ->where('is_active', true)
            ->get();

        $flowScores = [];

        foreach ($flows as $flow) {
            $score = $this->engine->calculate(
                $flow->user->role,
                $flow->classification ?? $flow->task_type,
                $flow->urgency_weight ?? 1
            );

            $userId = $flow->user_id;

            if (! isset($flowScores[$userId]) || $score > $flowScores[$userId]) {
                $flowScores[$userId] = $score;
            }
        }

        $monitoredUsers = User::whereNotNull('ip_address')->orderBy('name')->get();
        $entries = [];

        foreach ($monitoredUsers as $user) {
            $isOnline = $this->mikrotik->isDeviceOnline($user->ip_address, $onlineIps);
            $score = $flowScores[$user->id] ?? 0;

            $entries[$user->id] = [
                'user' => $user,
                'score' => $score,
                'is_online' => $isOnline,
            ];
        }

        $onlineScores = collect($entries)
            ->filter(fn ($entry) => $entry['is_online'] && $entry['score'] > 0)
            ->mapWithKeys(fn ($entry, $userId) => [$userId => $entry['score']])
            ->all();

        $totalScore = array_sum($onlineScores);
        $distribution = $this->engine->distributePool($onlineScores, $poolKbps);
        $rows = collect();
        $onlineCount = 0;
        $offlineCount = 0;

        foreach ($entries as $userId => $entry) {
            if ($entry['is_online']) {
                $onlineCount++;
            } else {
                $offlineCount++;
            }

            $shareKbps = $entry['is_online'] ? ($distribution[$userId] ?? 0) : 0;
            $sharePercent = ($entry['is_online'] && $totalScore > 0 && $entry['score'] > 0)
                ? round(($entry['score'] / $totalScore) * 100, 1)
                : 0;

            $rows->push((object) [
                'user' => $entry['user'],
                'score' => $entry['score'],
                'is_online' => $entry['is_online'],
                'share_percent' => $sharePercent,
                'share_kbps' => $shareKbps,
                'kbps_display' => $this->engine->formatKbpsDisplay($shareKbps),
                'bandwidth' => $shareKbps > 0 ? $this->engine->formatLimit($shareKbps) : '0k/0k',
                'last_seen_at' => now(),
            ]);
        }

        return [
            'pool_kbps' => $poolKbps,
            'pool_label' => $this->engine->formatLimit(max($poolKbps, 0)),
            'pool_display' => $this->engine->formatKbpsDisplay(max($poolKbps, 0)),
            'total_score' => $totalScore,
            'online_count' => $onlineCount,
            'offline_count' => $offlineCount,
            'users' => $rows->sortByDesc(fn ($row) => $row->is_online ? $row->share_kbps : -1)->values(),
        ];
    }

    public function forActiveFlows(int $poolKbps, array $onlineIps = []): Collection
    {
        return $this->build($poolKbps, $onlineIps)['users']
            ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
            ->values();
    }
}
