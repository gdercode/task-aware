<?php

namespace App\Services;

class ImportanceEngineService
{
    protected $roleWeights = [
        'dean' => 10,
        'lecturer' => 7,
        'student' => 4,
    ];

    protected $taskWeights = [
        'REAL_TIME' => 8,
        'DATA_TRANSFER' => 5,
        'STREAMING' => 3,
        'BULK' => 1,
        'NORMAL' => 2,
    ];

    public function calculate($userRole, $taskType, $urgency = 1)
    {
        $roleWeight = $this->roleWeights[$userRole] ?? 1;

        $taskWeight = $this->taskWeights[$taskType] ?? 1;

        return $roleWeight + $taskWeight + $urgency;
    }

    public function bandwidthFromScore($score)
    {
        if ($score >= 18) {
            return '10M/10M';
        }

        if ($score >= 14) {
            return '7M/7M';
        }

        if ($score >= 10) {
            return '5M/5M';
        }

        if ($score >= 6) {
            return '2M/2M';
        }

        return '1M/1M';
    }

    public function parseBandwidthToMbps(string $bandwidth): int
    {
        if (preg_match('/^(\d+)M/i', $bandwidth, $matches)) {
            return (int) $matches[1];
        }

        return 0;
    }

    public function formatMbpsTotal(int $mbps): string
    {
        return $mbps > 0 ? "{$mbps}M" : '0M';
    }

    public function formatLimit(int $mbps): string
    {
        return "{$mbps}M/{$mbps}M";
    }

    /**
     * Split the measured pool across users by score. Total allocated always equals poolMbps.
     *
     * @param  array<int|string, int>  $scores
     * @return array<int|string, int>
     */
    public function distributePool(array $scores, int $poolMbps): array
    {
        if ($scores === [] || $poolMbps <= 0) {
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
            $exact = ($score / $totalScore) * $poolMbps;
            $floor = (int) floor($exact);
            $allocations[$id] = $floor;
            $assigned += $floor;
            $fractions[$id] = $exact - $floor;
        }

        $remaining = $poolMbps - $assigned;
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
