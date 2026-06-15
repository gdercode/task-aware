<?php

namespace App\Services;

use App\Models\Flow;
use App\Models\User;

class TrafficSyncService
{
    public function syncFromRouter(MikrotikService $mikrotik, TrafficDetectionService $detector): int
    {
        $connections = $mikrotik->getConnections();
        $usersByIp = User::whereNotNull('ip_address')->get()->keyBy('ip_address');
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

            $flow = Flow::where('user_id', $user->id)
                ->where('is_active', true)
                ->where('destination', $dst)
                ->first();

            if ($flow) {
                $flow->update([
                    'classification' => $classification,
                    'task_type' => $classification,
                    'bytes' => max($flow->bytes, $bytes),
                    'source_ip' => $srcIp,
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
                ]);
            }

            $synced++;
        }

        return $synced;
    }
}
