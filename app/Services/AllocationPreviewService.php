<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;

class AllocationPreviewService
{
    public function __construct(
        protected ImportanceEngineService $engine,
        protected MikrotikService $mikrotik,
        protected UserActivityService $activity,
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
     *     activity: array<string, int>,
     *     users: Collection
     * }
     */
    public function build(int $poolKbps, array $onlineIps = []): array
    {
        $monitoredUsers = User::whereNotNull('ip_address')->orderBy('name')->get();
        $entries = [];

        foreach ($monitoredUsers as $user) {
            $isOnline = $this->mikrotik->isDeviceOnline($user->ip_address, $onlineIps);
            $effectiveScore = ($isOnline && $user->activity_status !== 'offline')
                ? (int) $user->effective_score
                : 0;

            if (! $isOnline) {
                $effectiveScore = 0;
            }

            $entries[$user->id] = [
                'user' => $user,
                'base_score' => (int) $user->base_score,
                'effective_score' => $effectiveScore,
                'activity_status' => $isOnline ? $user->activity_status : 'offline',
                'task_type' => $user->current_task_type,
                'is_online' => $isOnline,
            ];
        }

        $allocatableScores = collect($entries)
            ->filter(fn ($entry) => $entry['is_online'] && $entry['effective_score'] > 0)
            ->mapWithKeys(fn ($entry, $userId) => [$userId => $entry['effective_score']])
            ->all();

        $totalScore = array_sum($allocatableScores);
        $distribution = $this->engine->distributePool($allocatableScores, $poolKbps);
        $rows = collect();
        $onlineCount = 0;
        $offlineCount = 0;

        foreach ($entries as $userId => $entry) {
            if ($entry['is_online']) {
                $onlineCount++;
            } else {
                $offlineCount++;
            }

            $shareKbps = ($entry['effective_score'] > 0) ? ($distribution[$userId] ?? 0) : 0;
            $sharePercent = ($totalScore > 0 && $entry['effective_score'] > 0)
                ? round(($entry['effective_score'] / $totalScore) * 100, 1)
                : 0;

            $rows->push((object) [
                'user' => $entry['user'],
                'score' => $entry['effective_score'],
                'base_score' => $entry['base_score'],
                'activity_status' => $entry['activity_status'],
                'activity_label' => $this->activity->activityLabel($entry['activity_status']),
                'task_type' => $entry['task_type'],
                'is_online' => $entry['is_online'],
                'share_percent' => $sharePercent,
                'share_kbps' => $shareKbps,
                'kbps_display' => $this->engine->formatKbpsDisplay($shareKbps),
                'bandwidth' => $shareKbps > 0 ? $this->engine->formatLimit($shareKbps) : '0k/0k',
                'last_seen_at' => $entry['user']->last_active_at,
            ]);
        }

        return [
            'pool_kbps' => $poolKbps,
            'pool_label' => $this->engine->formatLimit(max($poolKbps, 0)),
            'pool_display' => $this->engine->formatKbpsDisplay(max($poolKbps, 0)),
            'total_score' => $totalScore,
            'online_count' => $onlineCount,
            'offline_count' => $offlineCount,
            'activity' => $this->activity->summarize($monitoredUsers),
            'users' => $rows->sortByDesc(fn ($row) => $row->share_kbps)->values(),
        ];
    }

    public function forActiveFlows(int $poolKbps, array $onlineIps = []): Collection
    {
        return $this->build($poolKbps, $onlineIps)['users']
            ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
            ->values();
    }
}
