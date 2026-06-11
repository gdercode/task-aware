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
}
