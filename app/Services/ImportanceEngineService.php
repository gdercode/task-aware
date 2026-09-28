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
