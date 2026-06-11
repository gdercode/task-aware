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

}

