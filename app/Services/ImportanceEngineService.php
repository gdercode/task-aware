<?php

namespace App\Services;

use App\Models\Role;

class ImportanceEngineService
{
    /** @var array<string, int> */
    protected array $roleWeights = [];

    protected float $roleWeightsLoadedAt = 0;

    protected $taskWeights = [
        'REAL_TIME' => 8,
        'DATA_TRANSFER' => 5,
        'STREAMING' => 3,
        'BULK' => 1,
        'NORMAL' => 2,
    ];

    public function calculate($userRole, $taskType, $urgency = 1)
    {
        return $this->roleScore((string) $userRole);
    }

    public function roleScore(string $userRole): int
    {
        return $this->roleWeights()[$userRole] ?? 1;
    }

    public function effectiveScore(int $baseScore, string $activityStatus, string $role): int
    {
        return match ($activityStatus) {
            'offline', 'idle' => 0,
            'low_usage' => $this->roleScore($role),
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
     * Higher priorities keep a strictly larger queue than lower priorities.
     * Computers with the same percentage can receive the same amount.
     *
     * @param  array<int|string, int>  $shares
     * @param  array<int|string, int>  $rolePercentages
     * @return array<int|string, int>
     */
    public function capSharesByRole(array $shares, array $rolePercentages): array
    {
        $grouped = [];

        foreach ($shares as $id => $kbps) {
            $grouped[(int) ($rolePercentages[$id] ?? 0)][$id] = (int) $kbps;
        }

        krsort($grouped);

        $result = [];
        $lowerMustStayBelow = null;

        foreach ($grouped as $members) {
            $groupShares = [];

            foreach ($members as $id => $kbps) {
                if ($lowerMustStayBelow !== null) {
                    $kbps = min($kbps, max(0, $lowerMustStayBelow - 1));
                }

                $groupShares[$id] = $kbps;
                $result[$id] = $kbps;
            }

            if ($groupShares !== []) {
                $lowerMustStayBelow = min($groupShares);
            }
        }

        return $result;
    }

    /**
     * While a higher priority is moving traffic, a lower priority cannot be given a larger rate.
     *
     * @param  array<int|string, int>  $shares
     * @param  array<int|string, int>  $rolePercentages
     * @param  array<int|string, int>  $usedKbps
     * @return array<int|string, int>
     */
    public function holdBelowActiveHigherPriority(array $shares, array $rolePercentages, array $usedKbps): array
    {
        $activeUse = [];

        foreach ($shares as $id => $kbps) {
            $used = (int) ($usedKbps[$id] ?? 0);
            if ($used < 1) {
                continue;
            }

            $percentage = (int) ($rolePercentages[$id] ?? 0);
            $activeUse[$percentage] = isset($activeUse[$percentage])
                ? min($activeUse[$percentage], $used)
                : $used;
        }

        if ($activeUse === []) {
            return $shares;
        }

        foreach ($shares as $id => $kbps) {
            $percentage = (int) ($rolePercentages[$id] ?? 0);
            $ceiling = null;

            foreach ($activeUse as $higherPercentage => $used) {
                if ($higherPercentage <= $percentage) {
                    continue;
                }

                $ceiling = $ceiling === null ? $used : min($ceiling, $used);
            }

            if ($ceiling === null) {
                continue;
            }

            $shares[$id] = min((int) $kbps, max(1, $ceiling - 1));
        }

        return $shares;
    }

    /**
     * RouterOS priority 1 is served first. Higher role percentages get the smaller number.
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
