<?php

namespace App\Services;

use App\Models\MikrotikSetting;
use RouterOS\Client;
use RouterOS\Exceptions\ConnectException;
use RouterOS\Query;

class MikrotikService
{
    protected ?Client $client = null;

    protected function settings(): MikrotikSetting
    {
        return MikrotikSetting::current();
    }

    protected function getClient(): Client
    {
        if ($this->client === null) {
            $settings = $this->settings();

            $this->client = new Client([
                'host' => $settings->host,
                'user' => config('mikrotik.user'),
                'pass' => config('mikrotik.pass'),
                'port' => $settings->port,
                'timeout' => config('mikrotik.timeout'),
            ]);
        }

        return $this->client;
    }

    public function resetClient(): void
    {
        $this->client = null;
    }

    public function connectionLabel(): string
    {
        $settings = $this->settings();

        return sprintf('%s:%d', $settings->host, $settings->port);
    }

    public function isReachable(): bool
    {
        try {
            $this->getClient()->query('/system/identity/print')->read();

            return true;
        } catch (\Throwable) {
            $this->resetClient();

            return false;
        }
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

    /**
     * Update or create a queue. Returns false when the router is unreachable.
     */
    public function updateQueue($name, $target, $maxLimit): bool
    {
        try {
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

                $this->getClient()->query($query)->read();
            } else {
                $this->createQueue($name, $target, $maxLimit);
            }

            return true;
        } catch (ConnectException $e) {
            $this->resetClient();

            return false;
        } catch (\Throwable $e) {
            $this->resetClient();

            throw $e;
        }
    }

    /**
     * Measure live traffic on the monitored WAN interface (bits/sec → Kbps).
     * Returns null when measurement fails (e.g. wrong interface name).
     */
    public function tryMeasureIncomingBandwidthKbps(): ?int
    {
        try {
            return $this->measureFromInterface();
        } catch (\Throwable) {
            $this->resetClient();

            return null;
        }
    }

    public function measureIncomingBandwidthKbps(): int
    {
        $kbps = $this->tryMeasureIncomingBandwidthKbps();

        if ($kbps === null) {
            throw new \RuntimeException(
                'Could not measure bandwidth on interface '.$this->settings()->monitor_interface
            );
        }

        return $kbps;
    }

    protected function measureFromInterface(): int
    {
        $interface = $this->settings()->monitor_interface ?: 'ether1';

        $query = new Query('/interface/monitor-traffic');
        $query->equal('interface', $interface);
        $query->equal('once', '');

        $result = $this->getClient()->query($query)->read();
        $sample = $result[0] ?? $result;

        $rxBps = (int) ($sample['rx-bits-per-second'] ?? 0);
        $txBps = (int) ($sample['tx-bits-per-second'] ?? 0);

        $totalBps = $rxBps + $txBps;

        return (int) ceil($totalBps / 1_000);
    }
}
