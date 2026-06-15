<?php

namespace App\Services;

use App\Models\Flow;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    protected ?Client $client = null;

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $this->client = new Client([
                'host' => env('MIKROTIK_HOST'),
                'user' => env('MIKROTIK_USER'),
                'pass' => env('MIKROTIK_PASS'),
                'port' => (int) env('MIKROTIK_PORT', 8728),
            ]);
        }

        return $this->client;
    }

    public function testConnection()
    {
        return $this->getClient()->query('/system/identity/print')->read();
    }

    public function createQueue($name, $target, $maxLimit)
    {
        $query = new Query('/queue/simple/add');
        $query->equal('name', $name);
        $query->equal('target', $target);
        $query->equal('max-limit', $maxLimit);

        return $this->getClient()->query($query)->read();
    }

    public function getConnections()
    {
        return $this->getClient()
            ->query('/ip/firewall/connection/print')
            ->read();
    }

    public function updateQueue($name, $target, $maxLimit)
    {
        $queues = $this->getClient()
            ->query('/queue/simple/print')
            ->read();

        $queueId = null;

        foreach ($queues as $queue) {
            if (($queue['name'] ?? '') === $name) {
                $queueId = $queue['.id'];
                break;
            }
        }

        if ($queueId) {
            $query = new Query('/queue/simple/set');
            $query->equal('.id', $queueId);
            $query->equal('max-limit', $maxLimit);

            return $this->getClient()->query($query)->read();
        }

        return $this->createQueue($name, $target, $maxLimit);
    }

    /**
     * Measure live traffic on the monitored WAN interface (bits/sec → Mbps).
     */
    public function measureIncomingBandwidthMbps(): int
    {
        try {
            return $this->measureFromInterface();
        } catch (\Throwable) {
            return $this->estimateFromActiveFlows();
        }
    }

    protected function measureFromInterface(): int
    {
        $interface = config('bandwidth.monitor_interface', 'ether1');

        $query = new Query('/interface/monitor-traffic');
        $query->equal('interface', $interface);
        $query->equal('once', '');

        $result = $this->getClient()->query($query)->read();
        $sample = $result[0] ?? $result;

        $rxBps = (int) ($sample['rx-bits-per-second'] ?? 0);
        $txBps = (int) ($sample['tx-bits-per-second'] ?? 0);

        $totalBps = $rxBps + $txBps;
        $mbps = (int) ceil($totalBps / 1_000_000);

        return max(1, $mbps);
    }

    /**
     * Fallback estimate when the router is unreachable (e.g. local dev).
     */
    protected function estimateFromActiveFlows(): int
    {
        $activeBytes = Flow::where('is_active', true)->sum('bytes');

        if ($activeBytes <= 0) {
            return 1;
        }

        $mbps = (int) ceil($activeBytes / 500_000);

        return max(1, min($mbps, 100));
    }
}
