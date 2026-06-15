<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\User;
use Illuminate\Support\Collection;

class UserActivityService
{
    public function __construct(
        protected ImportanceEngineService $engine,
    ) {}

    /**
     * @return array{status: string, base_score: int, effective_score: int, bytes_delta: int, task_type: ?string}
     */
    public function evaluate(User $user, int $connectionBytes, bool $isOnline): array
    {
        if (! $isOnline) {
            $this->markOffline($user);

            return [
                'status' => 'offline',
                'base_score' => 0,
                'effective_score' => 0,
                'bytes_delta' => 0,
                'task_type' => null,
            ];
        }

        $baseScore = $this->bestActiveFlowScore($user);
        $taskType = $this->bestActiveFlowTaskType($user);
        $previousBytes = (int) $user->last_traffic_bytes;
        $delta = $connectionBytes - $previousBytes;

        if ($connectionBytes < $previousBytes) {
            $delta = $connectionBytes;
        }

        $delta = max(0, $delta);
        $status = $this->resolveActivityStatus($user, $delta);
        $effectiveScore = $this->engine->effectiveScore($baseScore, $status, $user->role);

        if ($status === 'offline') {
            $baseScore = 0;
            $taskType = null;
            $this->deactivateUserFlows($user);
        } elseif ($status === 'idle') {
            $effectiveScore = 0;
            $this->deactivateUserFlows($user);
        }

        $user->update([
            'last_traffic_bytes' => $connectionBytes,
            'last_active_at' => $delta > 0 ? now() : $user->last_active_at,
            'activity_status' => $status,
            'base_score' => $baseScore,
            'effective_score' => $effectiveScore,
            'current_task_type' => $taskType,
        ]);

        Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->update(['importance_score' => $effectiveScore]);

        return [
            'status' => $status,
            'base_score' => $baseScore,
            'effective_score' => $effectiveScore,
            'bytes_delta' => $delta,
            'task_type' => $taskType,
        ];
    }

    protected function resolveActivityStatus(User $user, int $delta): string
    {
        if ($delta >= config('bandwidth.active_usage_bytes', 16384)) {
            return 'active';
        }

        if ($delta >= config('bandwidth.low_usage_bytes', 4096)) {
            return 'low_usage';
        }

        if ($delta > 0) {
            return 'low_usage';
        }

        $idleSeconds = config('bandwidth.idle_seconds', 45);
        $lastActive = $user->last_active_at;

        if (! $lastActive || $lastActive->diffInSeconds(now()) >= $idleSeconds) {
            return 'idle';
        }

        return $user->activity_status === 'low_usage' ? 'low_usage' : 'active';
    }

    protected function bestActiveFlowScore(User $user): int
    {
        $best = 0;

        foreach (Flow::where('user_id', $user->id)->where('is_active', true)->get() as $flow) {
            $score = $this->engine->calculate(
                $user->role,
                $flow->classification ?? $flow->task_type,
                $flow->urgency_weight ?? 1
            );
            $best = max($best, $score);
        }

        return $best;
    }

    protected function bestActiveFlowTaskType(User $user): ?string
    {
        $bestFlow = Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->orderByDesc('importance_score')
            ->orderByDesc('bytes')
            ->first();

        return $bestFlow?->classification ?? $bestFlow?->task_type;
    }

    protected function markOffline(User $user): void
    {
        $this->deactivateUserFlows($user);

        $user->update([
            'activity_status' => 'offline',
            'base_score' => 0,
            'effective_score' => 0,
            'current_task_type' => null,
        ]);
    }

    protected function deactivateUserFlows(User $user): void
    {
        Flow::where('user_id', $user->id)
            ->where('is_active', true)
            ->update([
                'is_active' => false,
                'importance_score' => 0,
            ]);
    }

    public function activityLabel(string $status): string
    {
        return match ($status) {
            'active' => 'Active',
            'low_usage' => 'Low usage',
            'idle' => 'Idle',
            'offline' => 'Offline',
            default => 'Unknown',
        };
    }

    /**
     * @param  Collection<int, User>  $users
     */
    public function summarize(Collection $users): array
    {
        return [
            'active' => $users->where('activity_status', 'active')->count(),
            'low_usage' => $users->where('activity_status', 'low_usage')->count(),
            'idle' => $users->where('activity_status', 'idle')->count(),
            'offline' => $users->where('activity_status', 'offline')->count(),
        ];
    }
}
