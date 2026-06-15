<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\User;

class TrafficSyncService
{
    public function __construct(
        protected UserActivityService $activity,
        protected ImportanceEngineService $engine,
    ) {}

    /**
     * Sync router connections, refresh task classifications, and update user activity.
     *
     * @param  array<string, true>  $onlineIps
     * @return array{synced: int, user_bytes: array<int, int>}
     */
    public function syncFromRouter(
        MikrotikService $mikrotik,
        TrafficDetectionService $detector,
        array $onlineIps = [],
    ): array {
        $connections = $mikrotik->getConnections();
        $usersByIp = User::whereNotNull('ip_address')
            ->get()
            ->keyBy(fn (User $user) => $mikrotik->normalizeIp($user->ip_address));
        $userBytes = [];
        $seenUserIds = [];
        $synced = 0;

        foreach ($connections as $conn) {
            $src = $conn['src-address'] ?? null;
            $dst = $conn['dst-address'] ?? 'unknown';

            if (! $src) {
                continue;
            }

            $srcIp = explode(':', $src)[0];
            $user = $usersByIp->get($srcIp);

            if (! $user) {
                continue;
            }

            $bytes = (int) ($conn['bytes'] ?? $conn['orig-bytes'] ?? 0);
            $classification = $detector->classify($dst, $bytes, false);

            $userBytes[$user->id] = ($userBytes[$user->id] ?? 0) + $bytes;
            $seenUserIds[$user->id] = true;

            $flow = Flow::where('user_id', $user->id)
                ->where('is_active', true)
                ->where('destination', $dst)
                ->first();

            $score = $this->engine->calculate(
                $user->role,
                $classification,
                $flow?->urgency_weight ?? 1
            );

            if ($flow) {
                $flow->update([
                    'classification' => $classification,
                    'task_type' => $classification,
                    'bytes' => max($flow->bytes, $bytes),
                    'source_ip' => $srcIp,
                    'importance_score' => $score,
                ]);
            } else {
                Flow::create([
                    'user_id' => $user->id,
                    'task_type' => $classification,
                    'priority' => 1,
                    'is_active' => true,
                    'source_ip' => $srcIp,
                    'destination' => $dst,
                    'classification' => $classification,
                    'bytes' => $bytes,
                    'urgency_weight' => 1,
                    'importance_score' => $score,
                ]);
            }

            $synced++;
        }

        foreach ($usersByIp as $user) {
            $isOnline = $mikrotik->isDeviceOnline($user->ip_address, $onlineIps);
            $bytes = $userBytes[$user->id] ?? 0;
            $hadConnections = isset($seenUserIds[$user->id]);

            $this->activity->evaluate($user, $bytes, $isOnline, $hadConnections);
        }

        return [
            'synced' => $synced,
            'user_bytes' => $userBytes,
        ];
    }
}
