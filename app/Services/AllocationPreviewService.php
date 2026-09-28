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
     * @param  array<int, array{download_kbps: int, upload_kbps: int, total_kbps: int}>|null  $userThroughput
     * @param  array<string, true>  $activeIps  Devices whose last bandwidth use is Now
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
    public function build(int $poolKbps, array $onlineIps = [], ?array $userThroughput = null, array $activeIps = []): array
    {
        $measuredPoolKbps = max(0, $poolKbps);
        $monitoredUsers = User::whereNotNull('ip_address')->orderBy('name')->get();
        $entries = $this->buildEntries($monitoredUsers, $onlineIps, $activeIps);

        $allocatableScores = collect($entries)
            ->filter(fn ($entry) => $entry['using_bandwidth'] && $entry['effective_score'] > 0)
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
        $userThroughput ??= $this->mikrotik->measureUserThroughputKbps();
        $rows = $this->buildRows($entries, $distribution, $totalScore, $userThroughput);
        $onlineCount = collect($entries)->where('is_online', true)->count();
        $offlineCount = collect($entries)->where('is_online', false)->count();
        $totalThroughputKbps = (int) $rows->sum('throughput_total_kbps');
        $totalAllocatedKbps = (int) $rows->sum('share_kbps');
        $rows = $this->sortByUsagePressure($rows);
        $usageSummary = $this->summarizeUsage($rows, $poolKbps, $totalThroughputKbps, $totalAllocatedKbps);

        return [
            'pool_kbps' => $poolKbps,
            'measured_pool_kbps' => $measuredPoolKbps,
            'pool_label' => $this->engine->formatLimit(max($poolKbps, 0)),
            'pool_display' => $this->engine->formatKbpsDisplay(max($poolKbps, 0)),
            'pool_using_fallback' => $poolUsingFallback,
            'total_score' => $totalScore,
            'total_throughput_kbps' => $totalThroughputKbps,
            'total_allocated_kbps' => $totalAllocatedKbps,
            'online_count' => $onlineCount,
            'offline_count' => $offlineCount,
            'activity' => [
                'active' => collect($entries)->where('activity_status', 'active')->count(),
                'low_usage' => collect($entries)->where('activity_status', 'low_usage')->count(),
                'idle' => collect($entries)->where('activity_status', 'idle')->count(),
                'offline' => collect($entries)->where('activity_status', 'offline')->count(),
                'unknown' => collect($entries)->where('activity_status', 'unknown')->count(),
            ],
            'users' => $rows,
        ] + $usageSummary;
    }

    /**
     * How live use sits against the queue this user was given.
     *
     * @return array{
     *     usage_percent: int,
     *     impact: string,
     *     impact_label: string,
     *     impact_detail: string,
     *     task_label: string,
     *     task_effect: string
     * }
     */
    public function describeUsage(int $usedKbps, int $allocatedKbps, string $activityStatus, ?string $taskType): array
    {
        $taskType = $taskType ?: 'NORMAL';
        $task = $this->taskCopy($taskType);

        if ($allocatedKbps <= 0) {
            return [
                'usage_percent' => 0,
                'impact' => 'none',
                'impact_label' => 'No allocation',
                'impact_detail' => $this->noAllocationReason($activityStatus),
                'task_label' => $task['label'],
                'task_effect' => $task['effect'],
            ];
        }

        $percent = (int) round(($usedKbps / $allocatedKbps) * 100);

        if ($usedKbps <= 0) {
            return [
                'usage_percent' => 0,
                'impact' => 'spare',
                'impact_label' => 'Held, not in use',
                'impact_detail' => 'This share is reserved from the pool, but no traffic is moving. Others do not receive it until this task or activity changes.',
                'task_label' => $task['label'],
                'task_effect' => $task['effect'],
            ];
        }

        if ($percent >= 100) {
            return [
                'usage_percent' => $percent,
                'impact' => 'capped',
                'impact_label' => 'At the limit',
                'impact_detail' => 'Live use has filled this queue. Further demand is held back by the allocation.',
                'task_label' => $task['label'],
                'task_effect' => $task['effect'],
            ];
        }

        if ($percent >= 80) {
            return [
                'usage_percent' => $percent,
                'impact' => 'tight',
                'impact_label' => 'Near the limit',
                'impact_detail' => 'Most of this allocation is already in use.',
                'task_label' => $task['label'],
                'task_effect' => $task['effect'],
            ];
        }

        return [
            'usage_percent' => $percent,
            'impact' => 'within',
            'impact_label' => 'Inside the allocation',
            'impact_detail' => 'Live use fits inside the queue. Unused room in this share stays with this user.',
            'task_label' => $task['label'],
            'task_effect' => $task['effect'],
        ];
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return array{usage_of_pool_percent: int, usage_of_allocated_percent: int, users_at_limit: int, headroom_kbps: int}
     */
    public function summarizeUsage(Collection $rows, int $poolKbps, int $throughputKbps, int $allocatedKbps): array
    {
        return [
            'usage_of_pool_percent' => $poolKbps > 0
                ? (int) round(($throughputKbps / $poolKbps) * 100)
                : 0,
            'usage_of_allocated_percent' => $allocatedKbps > 0
                ? (int) round(($throughputKbps / $allocatedKbps) * 100)
                : 0,
            'users_at_limit' => $rows->filter(fn ($row) => ($row->impact ?? '') === 'capped')->count(),
            'headroom_kbps' => max(0, $allocatedKbps - $throughputKbps),
        ];
    }

    /**
     * @param  Collection<int, User>  $monitoredUsers
     * @param  array<string, true>  $onlineIps
     * @param  array<string, true>  $activeIps
     * @return array<int, array<string, mixed>>
     */
    protected function buildEntries(Collection $monitoredUsers, array $onlineIps, array $activeIps): array
    {
        $entries = [];

        foreach ($monitoredUsers as $user) {
            $ip = $this->mikrotik->normalizeIp($user->ip_address);
            $isOnline = $this->mikrotik->isDeviceOnline($user->ip_address, $onlineIps);
            $usingBandwidth = $isOnline && $ip !== '' && isset($activeIps[$ip]);
            $activityStatus = $isOnline ? ($user->activity_status ?: 'unknown') : 'offline';
            $rolePercentage = $this->engine->roleScore((string) $user->role);
            $effectiveScore = $usingBandwidth ? $rolePercentage : 0;

            $entries[$user->id] = [
                'user' => $user,
                'base_score' => $rolePercentage,
                'role_percentage' => $rolePercentage,
                'effective_score' => $effectiveScore,
                'activity_status' => $activityStatus,
                'task_type' => $user->current_task_type ?? 'NORMAL',
                'is_online' => $isOnline,
                'using_bandwidth' => $usingBandwidth,
            ];
        }

        return $entries;
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int|string, int>  $distribution
     * @param  array<int, array{download_kbps: int, upload_kbps: int, total_kbps: int}>  $userThroughput
     */
    protected function buildRows(array $entries, array $distribution, int $totalScore, array $userThroughput = []): Collection
    {
        $rows = collect();

        foreach ($entries as $userId => $entry) {
            $shareKbps = ($entry['effective_score'] > 0) ? ($distribution[$userId] ?? 0) : 0;
            $sharePercent = ($totalScore > 0 && $entry['effective_score'] > 0)
                ? round(($entry['effective_score'] / $totalScore) * 100, 1)
                : 0;

            $tp = $userThroughput[$userId] ?? [
                'download_kbps' => 0,
                'upload_kbps' => 0,
                'total_kbps' => 0,
            ];

            $impact = ($entry['role_percentage'] ?? 0) <= 0
                ? [
                    'usage_percent' => 0,
                    'impact' => 'blocked',
                    'impact_label' => 'Blocked',
                    'impact_detail' => 'This role weight is 0%, so the router blocks this device from browsing.',
                    'task_label' => $this->taskCopy($entry['task_type'])['label'],
                    'task_effect' => 'No share. The queue is held at the minimum rate so traffic cannot pass.',
                ]
                : $this->describeUsage(
                    $tp['total_kbps'],
                    $shareKbps,
                    $entry['activity_status'],
                    $entry['task_type'],
                );

            $rows->push((object) array_merge($impact, [
                'user' => $entry['user'],
                'score' => $entry['effective_score'],
                'base_score' => $entry['base_score'],
                'role_percentage' => $entry['role_percentage'],
                'activity_status' => $entry['activity_status'],
                'activity_label' => $this->activity->activityLabel($entry['activity_status']),
                'task_type' => $entry['task_type'],
                'is_online' => $entry['is_online'],
                'using_bandwidth' => $entry['using_bandwidth'],
                'share_percent' => $sharePercent,
                'share_kbps' => $shareKbps,
                'kbps_display' => $this->engine->formatKbpsDisplay($shareKbps),
                'bandwidth' => $this->engine->queueLimitFor((int) $entry['role_percentage'], $shareKbps),
                'throughput_down_kbps' => $tp['download_kbps'],
                'throughput_up_kbps' => $tp['upload_kbps'],
                'throughput_total_kbps' => $tp['total_kbps'],
                'throughput_display' => $this->formatThroughputDisplay($tp),
                'last_seen_at' => $entry['user']->last_active_at,
            ]));
        }

        return $rows;
    }

    /**
     * @param  array{download_kbps: int, upload_kbps: int, total_kbps: int}  $tp
     */
    protected function formatThroughputDisplay(array $tp): string
    {
        if ($tp['total_kbps'] <= 0) {
            return '0 Kbps';
        }

        return sprintf(
            '%s Kbps (↓%s ↑%s)',
            number_format($tp['total_kbps']),
            number_format($tp['download_kbps']),
            number_format($tp['upload_kbps'])
        );
    }

    /**
     * @return array{label: string, effect: string}
     */
    protected function taskCopy(string $taskType): array
    {
        return match ($taskType) {
            'REAL_TIME' => [
                'label' => 'Real-time',
                'effect' => 'Raises this share of the pool',
            ],
            'DATA_TRANSFER' => [
                'label' => 'Upload',
                'effect' => 'Takes a medium share of the pool',
            ],
            'STREAMING' => [
                'label' => 'Streaming',
                'effect' => 'Takes a smaller share than real-time',
            ],
            'BULK' => [
                'label' => 'Bulk download',
                'effect' => 'Takes the smallest share of the pool',
            ],
            default => [
                'label' => 'General use',
                'effect' => 'Takes a standard share of the pool',
            ],
        };
    }

    protected function noAllocationReason(string $activityStatus): string
    {
        return match ($activityStatus) {
            'offline' => 'Device is offline, so this share returns to the pool.',
            'idle' => 'No recent traffic, so this share returns to the pool.',
            default => 'This activity is not receiving a share of the pool.',
        };
    }

    /**
     * @param  Collection<int, object>  $rows
     * @return Collection<int, object>
     */
    protected function sortByUsagePressure(Collection $rows): Collection
    {
        $rank = ['capped' => 4, 'tight' => 3, 'within' => 2, 'spare' => 1, 'none' => 0];

        return $rows->sortByDesc(function ($row) use ($rank) {
            return (($rank[$row->impact] ?? 0) * 1_000_000) + (int) $row->throughput_total_kbps;
        })->values();
    }

    public function forActiveFlows(int $poolKbps, array $onlineIps = []): Collection
    {
        return $this->build($poolKbps, $onlineIps)['users']
            ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
            ->values();
    }
}
