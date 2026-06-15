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
     *     measured_pool_kbps: int,
     *     pool_label: string,
     *     pool_display: string,
     *     pool_using_fallback: bool,
     *     total_score: int,
     *     online_count: int,
     *     offline_count: int,
     *     activity: array<string, int>,
     *     users: Collection
     * }
     */
    public function build(int $poolKbps, array $onlineIps = []): array
    {
        $measuredPoolKbps = max(0, $poolKbps);
        $monitoredUsers = User::whereNotNull('ip_address')->orderBy('name')->get();
        $entries = $this->buildEntries($monitoredUsers, $onlineIps);

        $allocatableScores = collect($entries)
            ->filter(fn ($entry) => $entry['is_online'] && $entry['effective_score'] > 0)
            ->mapWithKeys(fn ($entry, $userId) => [$userId => $entry['effective_score']])
            ->all();

        $totalScore = array_sum($allocatableScores);

        $eligibleOnline = collect($entries)->filter(
            fn ($entry) => $entry['is_online'] && in_array($entry['activity_status'], ['active', 'low_usage'], true)
        )->count();

        if ($measuredPoolKbps <= 0 && ($totalScore > 0 || $eligibleOnline > 0)) {
            $poolKbps = config('bandwidth.min_pool_kbps', 64);
        }

        $poolUsingFallback = $measuredPoolKbps <= 0 && $poolKbps > 0;
        $distribution = $this->engine->distributePool($allocatableScores, $poolKbps);
        $rows = $this->buildRows($entries, $distribution, $totalScore);
        $onlineCount = collect($entries)->where('is_online', true)->count();
        $offlineCount = collect($entries)->where('is_online', false)->count();

        return [
            'pool_kbps' => $poolKbps,
            'measured_pool_kbps' => $measuredPoolKbps,
            'pool_label' => $this->engine->formatLimit(max($poolKbps, 0)),
            'pool_display' => $this->engine->formatKbpsDisplay(max($poolKbps, 0)),
            'pool_using_fallback' => $poolUsingFallback,
            'total_score' => $totalScore,
            'online_count' => $onlineCount,
            'offline_count' => $offlineCount,
            'activity' => $this->activity->summarize($monitoredUsers),
            'users' => $rows->sortByDesc(fn ($row) => $row->share_kbps)->values(),
        ];
    }

    /**
     * @param  Collection<int, User>  $monitoredUsers
     * @param  array<string, true>  $onlineIps
     * @return array<int, array<string, mixed>>
     */
    protected function buildEntries(Collection $monitoredUsers, array $onlineIps): array
    {
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

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int|string, int>  $distribution
     */
    protected function buildRows(array $entries, array $distribution, int $totalScore): Collection
    {
        $rows = collect();

        foreach ($entries as $userId => $entry) {
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

        return $rows;
    }

    public function forActiveFlows(int $poolKbps, array $onlineIps = []): Collection
    {
        return $this->build($poolKbps, $onlineIps)['users']
            ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
            ->values();
    }
}
