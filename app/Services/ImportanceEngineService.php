<?php

namespace App\Services;

use App\Models\Role;
use App\Models\TaskPriority;
use Illuminate\Support\Facades\Cache;

class ImportanceEngineService
{
    /** @var array<string, int> */
    protected array $roleWeights = [];

    protected float $roleWeightsLoadedAt = 0;

    /** @var array<string, int> */
    protected array $fallbackTaskWeights = [
        'REAL_TIME' => 8,
        'LEARNING' => 7,
        'COLLABORATION' => 5,
        'DATA_TRANSFER' => 4,
        'STREAMING' => 2,
        'NORMAL' => 2,
        'BULK' => 1,
    ];

    /** @var array<string, int> */
    protected array $fallbackUrgencies = [
        'REAL_TIME' => 5,
        'LEARNING' => 4,
        'COLLABORATION' => 3,
        'DATA_TRANSFER' => 3,
        'STREAMING' => 2,
        'NORMAL' => 2,
        'BULK' => 1,
    ];

    /** @var array<string, int> */
    protected array $fallbackFailureRisks = [
        'REAL_TIME' => 5,
        'LEARNING' => 4,
        'COLLABORATION' => 3,
        'DATA_TRANSFER' => 2,
        'STREAMING' => 1,
        'NORMAL' => 2,
        'BULK' => 1,
    ];

    /**
     * Base priority Pi = Ri + Ti + Ui + Fi.
     * Congestion does not change this sum. Sustained congestion adjusts Pi later.
     *
     * @return array{role: int, task: int, urgency: int, failure: int, priority: int}
     */
    public function weights(string $userRole, string $taskType, ?int $urgency = null): array
    {
        $role = $this->roleScore($userRole);
        if ($role <= 0) {
            return [
                'role' => 0,
                'task' => 0,
                'urgency' => 0,
                'failure' => 0,
                'priority' => 0,
            ];
        }

        $task = $this->taskPriority($taskType);
        $urgency = $urgency === null
            ? $this->urgencyFor($taskType)
            : max(1, min(5, $urgency));
        $failure = $this->failureRisk($taskType);

        return [
            'role' => $role,
            'task' => $task,
            'urgency' => $urgency,
            'failure' => $failure,
            'priority' => $role + $task + $urgency + $failure,
        ];
    }

    public function calculate($userRole, $taskType, $urgency = 1, bool $congested = false): int
    {
        return $this->weights((string) $userRole, (string) $taskType, (int) $urgency)['priority'];
    }

    public function taskPriority(string $taskType): int
    {
        $weights = $this->taskWeightMap();

        return $weights[$taskType] ?? $weights['NORMAL'] ?? 2;
    }

    public function taskLabel(string $taskType): string
    {
        return TaskPriority::labelFor($taskType) ?? 'General use';
    }

    public function urgencyFor(string $taskType, int $bytes = 0): int
    {
        $urgencies = $this->urgencyMap();
        $urgency = $urgencies[$taskType] ?? $urgencies['NORMAL'] ?? 2;

        if (in_array($taskType, ['BULK', 'STREAMING'], true) && $bytes >= 50_000_000) {
            return 1;
        }

        return max(1, min(5, (int) $urgency));
    }

    public function isCriticalTask(string $taskType): bool
    {
        if (TaskPriority::weights() === []) {
            return in_array($taskType, ['REAL_TIME', 'LEARNING'], true);
        }

        return in_array($taskType, TaskPriority::criticalSlugs(), true);
    }

    public function failureRisk(string $taskType): int
    {
        $risks = TaskPriority::failureRisks();
        if ($risks === []) {
            $risks = $this->fallbackFailureRisks;
        }

        return max(1, (int) ($risks[$taskType] ?? $risks['NORMAL'] ?? 1));
    }

    public function isLowPriorityTask(string $taskType): bool
    {
        if ($this->isCriticalTask($taskType)) {
            return false;
        }

        return in_array($taskType, ['BULK', 'STREAMING'], true);
    }

    /**
     * One monitoring interval. Utilization must stay at or above Tc for consecutive
     * intervals before critical tasks are raised. Otherwise the response steps back.
     *
     * @return array{
     *     streak: int,
     *     pressure: float,
     *     phase: string,
     *     above_threshold: bool,
     *     required_intervals: int,
     *     threshold_percent: int
     * }
     */
    public function advanceAllocationState(int $utilizationPercent): array
    {
        $threshold = (int) config('bandwidth.congestion_percent', 70);
        $required = max(1, (int) config('bandwidth.congestion_intervals', 2));
        $restore = (float) config('bandwidth.restore_step', 0.25);
        $restore = max(0.05, min(1.0, $restore));

        $stored = Cache::store('database')->get('bandwidth.allocation_state', []);
        $pressure = (float) (is_array($stored) ? ($stored['pressure'] ?? 0) : 0);
        $above = $utilizationPercent >= $threshold;
        $streak = $above ? ((int) (is_array($stored) ? ($stored['streak'] ?? 0) : 0)) + 1 : 0;

        if ($streak >= $required) {
            $pressure = 1.0;
            $phase = 'protecting';
        } else {
            $pressure = max(0.0, $pressure - $restore);
            $phase = $pressure > 0 ? 'restoring' : 'normal';
        }

        $state = [
            'streak' => $streak,
            'pressure' => $pressure,
            'phase' => $phase,
            'above_threshold' => $above,
            'required_intervals' => $required,
            'threshold_percent' => $threshold,
        ];

        Cache::store('database')->put('bandwidth.allocation_state', $state, now()->addHours(6));

        return $state;
    }

    /**
     * While the congestion response is active, raise critical priorities and
     * shrink best-effort priorities. Best-effort tasks keep a fraction of Pi.
     *
     * @param  array<int|string, int>  $scores
     * @param  array<int|string, array{task?: string}>  $context
     * @return array<int|string, int>
     */
    public function applyCongestionResponse(array $scores, array $context, float $pressure): array
    {
        $pressure = max(0.0, min(1.0, $pressure));
        if ($pressure <= 0.0 || $scores === []) {
            return $scores;
        }

        $boost = max(0.0, (float) config('bandwidth.critical_priority_boost', 1));
        $keep = (float) config('bandwidth.low_priority_keep', 0.25);
        $keep = max(0.05, min(1.0, $keep));
        $adjusted = [];

        foreach ($scores as $id => $score) {
            $score = (int) $score;
            if ($score <= 0) {
                $adjusted[$id] = 0;

                continue;
            }

            $task = (string) ($context[$id]['task'] ?? 'NORMAL');
            $factor = 1.0;
            if ($this->isCriticalTask($task)) {
                $factor = 1 + ($pressure * $boost);
            } elseif ($this->isLowPriorityTask($task)) {
                $factor = 1 - ($pressure * (1 - $keep));
            }

            $adjusted[$id] = max(1, (int) round($score * $factor));
        }

        return $adjusted;
    }

    /**
     * @return array<string, int>
     */
    protected function taskWeightMap(): array
    {
        $weights = TaskPriority::weights();

        return $weights !== [] ? $weights : $this->fallbackTaskWeights;
    }

    /**
     * @return array<string, int>
     */
    protected function urgencyMap(): array
    {
        $urgencies = TaskPriority::urgencies();

        return $urgencies !== [] ? $urgencies : $this->fallbackUrgencies;
    }

    /**
     * @return array{
     *     congested: bool,
     *     utilization_percent: int,
     *     capacity_kbps: int,
     *     capacity_source: string,
     *     competing_users: int,
     *     threshold_percent: int,
     *     label: string
     * }
     */
    public function assessCongestion(int $poolKbps, int $throughputKbps, int $competingUsers): array
    {
        $configured = (int) config('bandwidth.link_capacity_kbps', 0);
        $capacity = $configured > 0 ? $configured : max(0, $poolKbps);
        $percent = ($capacity > 0 && $throughputKbps > 0)
            ? (int) round(($throughputKbps / $capacity) * 100)
            : 0;
        $threshold = (int) config('bandwidth.congestion_percent', 70);
        $congested = $capacity > 0 && $percent >= $threshold;

        return [
            'congested' => $congested,
            'utilization_percent' => $percent,
            'capacity_kbps' => $capacity,
            'capacity_source' => $configured > 0 ? 'configured' : 'observed',
            'competing_users' => $competingUsers,
            'threshold_percent' => $threshold,
            'label' => $congested ? 'Congested' : 'Stable',
        ];
    }

    public function roleScore(string $userRole): int
    {
        return $this->roleWeights()[$userRole] ?? 1;
    }

    public function effectiveScore(int $baseScore, string $activityStatus, string $role): int
    {
        if ($this->roleScore($role) <= 0 || $baseScore <= 0) {
            return 0;
        }

        return match ($activityStatus) {
            'offline', 'idle' => 0,
            'low_usage' => max(1, (int) round($baseScore * 0.4)),
            default => $baseScore,
        };
    }

    /**
     * @return array<string, int>
     */
    protected function roleWeights(): array
    {
        if ($this->roleWeightsLoadedAt === 0.0 || (microtime(true) - $this->roleWeightsLoadedAt) > 5) {
            $this->roleWeights = Role::query()->pluck('percentage', 'slug')->map(fn ($percentage) => (int) $percentage)->all();
            $this->roleWeightsLoadedAt = microtime(true);
        }

        return $this->roleWeights;
    }

    public function bandwidthFromScore($score)
    {
        if ($score >= 18) {
            return '10240k/10240k';
        }

        if ($score >= 14) {
            return '7168k/7168k';
        }

        if ($score >= 10) {
            return '5120k/5120k';
        }

        if ($score >= 6) {
            return '2048k/2048k';
        }

        return '1024k/1024k';
    }

    public function parseBandwidthToKbps(string $bandwidth): int
    {
        if (preg_match('/^(\d+)k/i', $bandwidth, $matches)) {
            return (int) $matches[1];
        }

        if (preg_match('/^(\d+)M/i', $bandwidth, $matches)) {
            return (int) $matches[1] * 1000;
        }

        return 0;
    }

    public function formatKbpsTotal(int $kbps): string
    {
        return $kbps > 0 ? "{$kbps}k" : '0k';
    }

    public function formatKbpsDisplay(int $kbps): string
    {
        return number_format($kbps).' Kbps';
    }

    public function formatLimit(int $kbps): string
    {
        return "{$kbps}k/{$kbps}k";
    }

    /**
     * RouterOS treats max-limit 0/0 as unlimited. 1/1 is the smallest rate and stops browsing.
     */
    public function blockedLimit(): string
    {
        return '1/1';
    }

    public function queueLimitFor(int $rolePercentage, int $shareKbps, int $poolKbps = 0): string
    {
        if ($rolePercentage <= 0 || $shareKbps <= 0) {
            return $this->blockedLimit();
        }

        return $this->formatLimit($shareKbps);
    }

    /**
     * During congestion, keep a floor under critical tasks and cap bulk and streaming.
     *
     * @param  array<int|string, int>  $scores
     * @param  array<int|string, array{task?: string, active?: bool}>  $context
     * @return array<int|string, int>
     */
    public function shapeShares(array $scores, array $context, int $poolKbps, bool $congested): array
    {
        $shares = $this->distributePool($scores, $poolKbps);

        if (! $congested || $shares === [] || $poolKbps <= 0) {
            return $shares;
        }

        $criticalIds = [];
        foreach ($scores as $id => $score) {
            if ($score <= 0 || empty($context[$id]['active'])) {
                continue;
            }

            if ($this->isCriticalTask((string) ($context[$id]['task'] ?? 'NORMAL'))) {
                $criticalIds[] = $id;
            }
        }

        if ($criticalIds !== []) {
            $floor = (int) config('bandwidth.critical_floor_kbps', 512);
            $budget = (int) floor($poolKbps * ((int) config('bandwidth.critical_budget_percent', 70)) / 100);
            $each = min($floor, intdiv(max($budget, 0), count($criticalIds)));

            if ($each > 0) {
                foreach ($criticalIds as $id) {
                    $shares[$id] = max((int) ($shares[$id] ?? 0), $each);
                }

                $shares = $this->trimToPool($shares, $poolKbps, $criticalIds);
            }
        }

        return $this->capDeferrable($shares, $scores, $context, $poolKbps, $criticalIds !== []);
    }

    /**
     * Move bandwidth a device is not using to a device that has filled its queue.
     * The donor keeps headroom so a higher-priority device can grow on the next cycles.
     *
     * @param  array<int|string, int>  $shares
     * @param  array<int|string, int>  $usedKbps
     * @param  array<int|string, int>  $scores
     * @param  array<int|string, array{task?: string}>  $context
     * @return array<int|string, int>
     */
    public function releaseUnused(array $shares, array $usedKbps, array $scores, array $context = [], bool $congested = false): array
    {
        $spareRatio = (float) config('bandwidth.spare_use_ratio', 0.5);
        $tightRatio = (float) config('bandwidth.tight_use_ratio', 0.8);
        $keepMultiple = (float) config('bandwidth.spare_keep_multiple', 2);
        $keepScoreRatio = (float) config('bandwidth.spare_keep_score_ratio', 0.25);

        $give = [];
        $recipients = [];

        foreach ($shares as $id => $share) {
            $share = (int) $share;
            if ($share <= 0) {
                continue;
            }

            $used = max(0, (int) ($usedKbps[$id] ?? 0));
            $ratio = $used / $share;
            $task = (string) ($context[$id]['task'] ?? 'NORMAL');
            $idleKbps = (int) config('bandwidth.spare_idle_kbps', 256);

            if ($used > $idleKbps) {
                if ($ratio >= $tightRatio && (! $congested || ! in_array($task, ['BULK', 'STREAMING'], true))) {
                    $recipients[$id] = max(1, (int) ($scores[$id] ?? 1));
                }

                continue;
            }

            if ($ratio >= $spareRatio) {
                continue;
            }

            $keep = (int) round(max($used * $keepMultiple, $share * $keepScoreRatio));
            $keep = min($share, max($keep, $used));
            if ($share > $keep) {
                $give[$id] = $share - $keep;
            }
        }

        if ($give === [] || $recipients === []) {
            return $shares;
        }

        foreach ($give as $id => $kbps) {
            $shares[$id] -= $kbps;
        }

        foreach ($this->distributePool($recipients, (int) array_sum($give)) as $id => $extra) {
            $shares[$id] = ($shares[$id] ?? 0) + $extra;
        }

        return $shares;
    }

    /**
     * @param  array<int|string, int>  $shares
     * @param  list<int|string>  $protectIds
     * @return array<int|string, int>
     */
    protected function trimToPool(array $shares, int $poolKbps, array $protectIds): array
    {
        $over = array_sum($shares) - $poolKbps;
        if ($over <= 0) {
            return $shares;
        }

        $protected = array_flip($protectIds);
        $donors = [];
        foreach ($shares as $id => $kbps) {
            if (! isset($protected[$id]) && $kbps > 0) {
                $donors[$id] = $kbps;
            }
        }

        $over = $this->takeProportionally($shares, $donors, $over);

        if ($over > 0) {
            $protectedShares = [];
            foreach ($protectIds as $id) {
                if (($shares[$id] ?? 0) > 0) {
                    $protectedShares[$id] = $shares[$id];
                }
            }

            $this->takeProportionally($shares, $protectedShares, $over);
        }

        return $shares;
    }

    /**
     * @param  array<int|string, int>  $shares
     * @param  array<int|string, int>  $from
     */
    protected function takeProportionally(array &$shares, array $from, int $amount): int
    {
        $weights = [];
        foreach ($from as $id => $kbps) {
            $weights[$id] = min((int) $kbps, (int) ($shares[$id] ?? 0));
        }

        $available = array_sum($weights);
        $take = min($amount, $available);
        if ($take <= 0 || $available <= 0) {
            return $amount;
        }

        $removed = 0;
        foreach ($weights as $id => $kbps) {
            $cut = (int) floor($take * ($kbps / $available));
            $cut = min($cut, (int) $shares[$id]);
            $shares[$id] -= $cut;
            $removed += $cut;
        }

        $left = $take - $removed;
        arsort($weights);

        foreach (array_keys($weights) as $id) {
            if ($left <= 0) {
                break;
            }

            if (($shares[$id] ?? 0) <= 0) {
                continue;
            }

            $shares[$id]--;
            $left--;
        }

        return $amount - ($take - $left);
    }

    /**
     * @param  array<int|string, int>  $shares
     * @param  array<int|string, int>  $scores
     * @param  array<int|string, array{task?: string}>  $context
     * @return array<int|string, int>
     */
    protected function capDeferrable(array $shares, array $scores, array $context, int $poolKbps, bool $hasCritical): array
    {
        if (! $hasCritical) {
            return $shares;
        }

        $caps = [
            'BULK' => (int) config('bandwidth.bulk_cap_percent', 10),
            'STREAMING' => (int) config('bandwidth.streaming_cap_percent', 20),
        ];
        $excess = 0;

        foreach ($shares as $id => $kbps) {
            $task = (string) ($context[$id]['task'] ?? 'NORMAL');
            if (! isset($caps[$task])) {
                continue;
            }

            $cap = (int) floor($poolKbps * $caps[$task] / 100);
            if ($kbps > $cap) {
                $excess += $kbps - $cap;
                $shares[$id] = $cap;
            }
        }

        if ($excess <= 0) {
            return $shares;
        }

        $recipients = [];
        foreach ($scores as $id => $score) {
            $task = (string) ($context[$id]['task'] ?? 'NORMAL');
            if ($score <= 0 || isset($caps[$task])) {
                continue;
            }

            $recipients[$id] = $score;
        }

        if ($recipients === []) {
            return $shares;
        }

        foreach ($this->distributePool($recipients, $excess) as $id => $kbps) {
            $shares[$id] = ($shares[$id] ?? 0) + $kbps;
        }

        return $shares;
    }

    /**
     * RouterOS priority 1 is served first. Higher importance scores get the smaller number.
     *
     * @param  array<int|string, int>  $rolePercentages
     * @return array<int|string, int>
     */
    public function queuePriorities(array $rolePercentages): array
    {
        $levels = array_values(array_unique(array_map('intval', $rolePercentages)));
        rsort($levels);
        $rank = [];

        foreach ($levels as $index => $percentage) {
            $rank[$percentage] = min(8, $index + 1);
        }

        $priorities = [];

        foreach ($rolePercentages as $id => $percentage) {
            $priorities[$id] = $rank[(int) $percentage] ?? 8;
        }

        return $priorities;
    }

    /**
     * Split the measured pool across users by score. Total allocated always equals poolKbps.
     *
     * @param  array<int|string, int>  $scores
     * @return array<int|string, int>
     */
    public function distributePool(array $scores, int $poolKbps): array
    {
        if ($scores === [] || $poolKbps <= 0) {
            return [];
        }

        $totalScore = array_sum($scores);
        if ($totalScore <= 0) {
            return array_fill_keys(array_keys($scores), 0);
        }

        $allocations = [];
        $fractions = [];
        $assigned = 0;

        foreach ($scores as $id => $score) {
            $exact = ($score / $totalScore) * $poolKbps;
            $floor = (int) floor($exact);
            $allocations[$id] = $floor;
            $assigned += $floor;
            $fractions[$id] = $exact - $floor;
        }

        $remaining = $poolKbps - $assigned;
        arsort($fractions);

        foreach (array_keys($fractions) as $id) {
            if ($remaining <= 0) {
                break;
            }
            $allocations[$id]++;
            $remaining--;
        }

        return $allocations;
    }
}
