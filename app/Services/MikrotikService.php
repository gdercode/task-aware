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

    /** Exposed for diagnostics — prefer higher-level API methods in application code. */
    public function getRouterClient(): Client
    {
        return $this->getClient();
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
     * IPs currently on the network (ARP, connections, DHCP, hotspot).
     * Each source is queried independently so one failure does not block others.
     *
     * @return array<string, true>
     */
    public function tryGetOnlineDeviceIps(): array
    {
        return app(RouterDeviceDetectionService::class)->diagnose()['online_ips'];
    }

    public function isDeviceOnline(?string $ip, array $onlineIps): bool
    {
        $ip = trim((string) $ip);

        return $ip !== '' && isset($onlineIps[$ip]);
    }

    public function normalizeIp(?string $ip): string
    {
        return trim((string) $ip);
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
     * Measure the bandwidth pool using interface monitor + firewall connection rates.
     *
     * @return array{
     *     kbps: int,
     *     interface_kbps: int,
     *     connection_kbps: int,
     *     source: string,
     *     interface: string,
     *     interface_error: ?string
     * }
     */
    public function measurePoolKbps(): array
    {
        $interface = $this->settings()->monitor_interface ?: 'ether1';
        $interfaceKbps = 0;
        $interfaceError = null;

        try {
            $interfaceKbps = $this->measureFromInterface($interface);
        } catch (\Throwable $e) {
            $interfaceError = $e->getMessage();
            $this->resetClient();
        }

        $connectionKbps = $this->measureFromConnections();
        $kbps = max($interfaceKbps, $connectionKbps);

        $source = 'none';
        if ($kbps > 0) {
            $source = $interfaceKbps >= $connectionKbps ? 'interface' : 'connections';
        }

        return [
            'kbps' => $kbps,
            'interface_kbps' => $interfaceKbps,
            'connection_kbps' => $connectionKbps,
            'source' => $source,
            'interface' => $interface,
            'interface_error' => $interfaceError,
        ];
    }

    /**
     * @return list<array{name: string, kbps: int|null}>
     */
    public function getInterfaceTrafficSamples(): array
    {
        try {
            $samples = [];

            foreach ($this->getRunningInterfaceNames() as $name) {
                try {
                    $samples[] = ['name' => $name, 'kbps' => $this->measureFromInterface($name)];
                } catch (\Throwable) {
                    $samples[] = ['name' => $name, 'kbps' => null];
                }
            }

            usort($samples, fn ($a, $b) => ($b['kbps'] ?? 0) <=> ($a['kbps'] ?? 0));

            return array_slice($samples, 0, 10);
        } catch (\Throwable) {
            $this->resetClient();

            return [];
        }
    }

    /**
     * @return list<string>
     */
    public function getRunningInterfaceNames(): array
    {
        $names = [];

        foreach ($this->getClient()->query('/interface/print')->read() as $iface) {
            $running = $iface['running'] ?? false;
            if ($running !== 'true' && $running !== true) {
                continue;
            }

            $name = $iface['name'] ?? null;
            if ($name && ! str_contains($name, '<')) {
                $names[] = $name;
            }
        }

        sort($names);

        return $names;
    }

    /**
     * Measure live traffic (bits/sec → Kbps). Returns null only when interface query fails.
     */
    public function tryMeasureIncomingBandwidthKbps(): ?int
    {
        $pool = $this->measurePoolKbps();

        if ($pool['interface_error'] && $pool['kbps'] === 0) {
            return null;
        }

        return $pool['kbps'];
    }

    public function measureIncomingBandwidthKbps(): int
    {
        $pool = $this->measurePoolKbps();

        if ($pool['interface_error'] && $pool['kbps'] === 0) {
            throw new \RuntimeException(
                'Could not measure bandwidth on interface '.$pool['interface'].': '.$pool['interface_error']
            );
        }

        return $pool['kbps'];
    }

    protected function measureFromConnections(): int
    {
        try {
            $totalBps = 0;

            foreach ($this->getConnections() as $conn) {
                $totalBps += (int) ($conn['orig-rate'] ?? 0);
                $totalBps += (int) ($conn['repl-rate'] ?? 0);
            }

            return (int) ceil($totalBps / 1_000);
        } catch (\Throwable) {
            $this->resetClient();

            return 0;
        }
    }

    protected function measureFromInterface(?string $interface = null): int
    {
        $interface = $interface ?: ($this->settings()->monitor_interface ?: 'ether1');

        $query = new Query('/interface/monitor-traffic');
        $query->equal('interface', $interface);
        $query->equal('once', '');

        $result = $this->getClient()->query($query)->read();
        $sample = $result[0] ?? $result;

        $rxBps = (int) ($sample['rx-bits-per-second'] ?? 0);
        $txBps = (int) ($sample['tx-bits-per-second'] ?? 0);

        return (int) ceil(($rxBps + $txBps) / 1_000);
    }
}
