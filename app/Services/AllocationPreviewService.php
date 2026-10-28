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
        $userThroughput ??= $this->mikrotik->measureUserThroughputKbps();
        $flowsByUser = Flow::query()
            ->whereIn('user_id', $monitoredUsers->pluck('id'))
            ->where('is_active', true)
            ->get()
            ->groupBy('user_id');

        $throughputKbps = 0;
        $competingUsers = 0;
        foreach ($monitoredUsers as $user) {
            $presence = $this->presence($user, $onlineIps, $activeIps);
            $used = (int) ($userThroughput[$user->id]['total_kbps'] ?? 0);
            $throughputKbps += $used;

            if ($presence['is_online'] && $presence['role_percentage'] > 0 && ($presence['using_bandwidth'] || $presence['activity_status'] === 'active' || $used > 0)) {
                $competingUsers++;
            }
        }

        $eligibleOnline = $competingUsers;
        if ($measuredPoolKbps <= 0 && $eligibleOnline > 0) {
            $poolKbps = (int) config('bandwidth.min_pool_kbps', 64);
        }

        $congestion = $this->engine->assessCongestion($poolKbps, $throughputKbps, $competingUsers);
        $entries = $this->buildEntries($monitoredUsers, $onlineIps, $activeIps, $flowsByUser, $congestion['congested']);

        $allocatableScores = collect($entries)
            ->filter(fn ($entry) => $entry['is_online'] && $entry['effective_score'] > 0)
            ->mapWithKeys(fn ($entry, $userId) => [$userId => $entry['effective_score']])
            ->all();

        $totalScore = array_sum($allocatableScores);
        $poolUsingFallback = $measuredPoolKbps <= 0 && $poolKbps > 0;
        $context = [];
        foreach ($entries as $userId => $entry) {
            $context[$userId] = [
                'task' => $entry['task_type'],
                'active' => $entry['activity_status'] === 'active' || $entry['using_bandwidth'],
            ];
        }
        $rawShares = $this->engine->distributePool($allocatableScores, $poolKbps);
        $usedKbps = [];
        foreach ($entries as $userId => $entry) {
            $usedKbps[$userId] = (int) ($userThroughput[$userId]['total_kbps'] ?? 0);
        }
        $distribution = $this->engine->shapeShares($allocatableScores, $context, $poolKbps, $congestion['congested']);
        $distribution = $this->engine->releaseUnused(
            $distribution,
            $usedKbps,
            $allocatableScores,
            $context,
            $congestion['congested'],
        );
        $pulledBack = [];
        foreach ($rawShares as $userId => $kbps) {
            if (($distribution[$userId] ?? 0) < $kbps) {
                $pulledBack[$userId] = true;
            }
        }
        $rows = $this->buildRows($entries, $distribution, $totalScore, $userThroughput, $poolKbps, $pulledBack);
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
            'congested' => $congestion['congested'],
            'congestion_label' => $congestion['label'],
            'utilization_percent' => $congestion['utilization_percent'],
            'capacity_kbps' => $congestion['capacity_kbps'],
            'capacity_source' => $congestion['capacity_source'],
            'competing_users' => $congestion['competing_users'],
            'congestion_threshold_percent' => $congestion['threshold_percent'],
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
     * @param  Collection<int|string, Collection<int, Flow>>  $flowsByUser
     * @return array<int, array<string, mixed>>
     */
    protected function buildEntries(Collection $monitoredUsers, array $onlineIps, array $activeIps, Collection $flowsByUser, bool $congested): array
    {
        $entries = [];

        foreach ($monitoredUsers as $user) {
            $presence = $this->presence($user, $onlineIps, $activeIps);
            $task = $this->dominantTask($user, $flowsByUser->get($user->id, collect()), $congested);
            $baseScore = $this->engine->calculate($user->role, $task['task'], $task['urgency'], $congested);
            $effectiveScore = $presence['is_online']
                ? $this->engine->effectiveScore($baseScore, $presence['activity_status'], (string) $user->role)
                : 0;

            $entries[$user->id] = [
                'user' => $user,
                'base_score' => $baseScore,
                'role_percentage' => $presence['role_percentage'],
                'effective_score' => $effectiveScore,
                'activity_status' => $presence['activity_status'],
                'task_type' => $task['task'],
                'urgency' => $task['urgency'],
                'task_priority' => $task['priority'],
                'is_online' => $presence['is_online'],
                'using_bandwidth' => $presence['using_bandwidth'],
            ];
        }

        return $entries;
    }

    /**
     * @param  array<string, true>  $onlineIps
     * @param  array<string, true>  $activeIps
     * @return array{is_online: bool, using_bandwidth: bool, activity_status: string, role_percentage: int}
     */
    protected function presence(User $user, array $onlineIps, array $activeIps): array
    {
        $ip = $this->mikrotik->normalizeIp($user->ip_address);
        $isOnline = $this->mikrotik->isDeviceOnline($user->ip_address, $onlineIps);

        return [
            'is_online' => $isOnline,
            'using_bandwidth' => $isOnline && $ip !== '' && isset($activeIps[$ip]),
            'activity_status' => $isOnline ? ($user->activity_status ?: 'unknown') : 'offline',
            'role_percentage' => $this->engine->roleScore((string) $user->role),
        ];
    }

    /**
     * @param  Collection<int, Flow>  $flows
     * @return array{task: string, urgency: int, priority: int}
     */
    protected function dominantTask(User $user, Collection $flows, bool $congested): array
    {
        $best = null;
        $bestScore = -1;

        foreach ($flows as $flow) {
            $task = $flow->classification ?: $flow->task_type ?: 'NORMAL';
            $urgency = (int) ($flow->urgency_weight ?? 0);
            if ($urgency < 1) {
                $urgency = $this->engine->urgencyFor($task, (int) $flow->bytes);
            }

            $score = $this->engine->calculate($user->role, $task, $urgency, $congested);
            if ($score > $bestScore) {
                $bestScore = $score;
                $best = [
                    'task' => $task,
                    'urgency' => $urgency,
                    'priority' => $this->engine->taskPriority($task),
                ];
            }
        }

        if ($best !== null) {
            return $best;
        }

        $task = $user->current_task_type ?: 'NORMAL';

        return [
            'task' => $task,
            'urgency' => $this->engine->urgencyFor($task),
            'priority' => $this->engine->taskPriority($task),
        ];
    }

    /**
     * @param  array<int, array<string, mixed>>  $entries
     * @param  array<int|string, int>  $distribution
     * @param  array<int, array{download_kbps: int, upload_kbps: int, total_kbps: int}>  $userThroughput
     */
    protected function buildRows(array $entries, array $distribution, int $totalScore, array $userThroughput = [], int $poolKbps = 0, array $pulledBack = []): Collection
    {
        $rows = collect();

        foreach ($entries as $userId => $entry) {
            $shareKbps = ($entry['effective_score'] > 0) ? ($distribution[$userId] ?? 0) : 0;
            $sharePercent = ($poolKbps > 0 && $shareKbps > 0)
                ? round(($shareKbps / $poolKbps) * 100, 1)
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
                'urgency' => $entry['urgency'],
                'task_priority' => $entry['task_priority'],
                'is_online' => $entry['is_online'],
                'using_bandwidth' => $entry['using_bandwidth'],
                'share_percent' => $sharePercent,
                'share_kbps' => $shareKbps,
                'force_reset' => isset($pulledBack[$userId]),
                'kbps_display' => $this->engine->formatKbpsDisplay($shareKbps),
                'bandwidth' => $this->engine->queueLimitFor((int) $entry['role_percentage'], $shareKbps, $poolKbps),
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
                'label' => 'Video conferencing',
                'effect' => 'High task priority and urgency. Protected while the network is congested.',
            ],
            'LEARNING' => [
                'label' => 'Online learning',
                'effect' => 'High institutional priority. Kept above bulk traffic during congestion.',
            ],
            'COLLABORATION' => [
                'label' => 'Digital collaboration',
                'effect' => 'Cloud and collaboration traffic is ranked above general use.',
            ],
            'DATA_TRANSFER' => [
                'label' => 'Data transfer',
                'effect' => 'Medium priority from the task and its urgency.',
            ],
            'STREAMING' => [
                'label' => 'Streaming',
                'effect' => 'Yields bandwidth when critical activities need the link.',
            ],
            'BULK' => [
                'label' => 'Bulk download',
                'effect' => 'Lowest priority. Capped while the network is congested.',
            ],
            default => [
                'label' => 'General use',
                'effect' => 'Share follows role, task priority, and urgency.',
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
