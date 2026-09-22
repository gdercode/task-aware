<?php

namespace App\Services;

use App\Models\MikrotikSetting;
use App\Models\User;
use Illuminate\Support\Facades\Cache;
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

    public function monitorInterfaceName(): string
    {
        return $this->settings()->monitor_interface ?: 'ether1';
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
     * Apply queue limits with one queue-list read for the whole cycle.
     *
     * @param  list<array{name: string, target: string, max_limit: string}>  $assignments
     * @return array<string, bool>
     */
    public function syncQueueLimits(array $assignments): array
    {
        $results = [];

        if ($assignments === []) {
            return $results;
        }

        try {
            $queues = $this->getClient()->query('/queue/simple/print')->read();
        } catch (ConnectException $e) {
            $this->resetClient();

            foreach ($assignments as $row) {
                $results[$row['name']] = false;
            }

            return $results;
        } catch (\Throwable $e) {
            $this->resetClient();

            throw $e;
        }

        $idsByName = [];

        foreach ($queues as $queue) {
            if (isset($queue['name'], $queue['.id'])) {
                $idsByName[$queue['name']] = $queue['.id'];
            }
        }

        $disconnected = false;

        foreach ($assignments as $row) {
            if ($disconnected) {
                $results[$row['name']] = false;

                continue;
            }

            try {
                if (isset($idsByName[$row['name']])) {
                    $query = new Query('/queue/simple/set');
                    $query->equal('.id', $idsByName[$row['name']]);
                    $query->equal('max-limit', $row['max_limit']);
                    $this->getClient()->query($query)->read();
                } else {
                    $this->createQueue($row['name'], $row['target'], $row['max_limit']);
                }

                $results[$row['name']] = true;
            } catch (ConnectException $e) {
                $this->resetClient();
                $results[$row['name']] = false;
                $disconnected = true;
            }
        }

        return $results;
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
    public function measurePoolKbps(?array $connections = null): array
    {
        $interface = $this->monitorInterfaceName();
        $interfaceKbps = 0;
        $interfaceError = null;

        try {
            $interfaceKbps = $this->measureFromInterface($interface);
        } catch (\Throwable $e) {
            $interfaceError = $e->getMessage();
            $this->resetClient();
        }

        return $this->poolFromMeasurements(
            $interfaceKbps,
            $interfaceError,
            $connections ?? $this->safeConnections(),
        );
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     * @return array{
     *     kbps: int,
     *     interface_kbps: int,
     *     connection_kbps: int,
     *     source: string,
     *     interface: string,
     *     interface_error: ?string
     * }
     */
    public function poolFromMeasurements(int $interfaceKbps, ?string $interfaceError, array $connections): array
    {
        $connectionKbps = $this->sumConnectionRatesKbps($connections);
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
            'interface' => $this->monitorInterfaceName(),
            'interface_error' => $interfaceError,
        ];
    }

    public function measureInterfaceKbps(string $interface): int
    {
        return $this->measureFromInterface($interface);
    }

    /**
     * Interface names plus the latest known rate. Does not probe every interface.
     *
     * @return list<array{name: string, kbps: int|null}>
     */
    public function interfaceTrafficSnapshot(string $monitor, ?int $monitorKbps): array
    {
        $names = [];

        try {
            $names = $this->getRunningInterfaceNames();
        } catch (\Throwable) {
            $this->resetClient();
        }

        $cached = Cache::store('file')->get('bandwidth.interface_kbps', []);
        if (! is_array($cached)) {
            $cached = [];
        }

        if ($monitor !== '') {
            $cached[$monitor] = $monitorKbps;
        }

        if ($names !== []) {
            $cached = array_intersect_key($cached, array_flip(array_merge($names, [$monitor])));
        }

        Cache::store('file')->put('bandwidth.interface_kbps', $cached, now()->addHour());

        $listNames = $names !== [] ? $names : array_keys($cached);
        $samples = [];

        foreach ($listNames as $name) {
            $samples[] = [
                'name' => $name,
                'kbps' => array_key_exists($name, $cached) ? $cached[$name] : null,
            ];
        }

        usort($samples, fn ($a, $b) => ($b['kbps'] ?? -1) <=> ($a['kbps'] ?? -1));

        return array_slice($samples, 0, 10);
    }

    /**
     * Measure one non-monitor interface so the picker fills in without blocking the report.
     */
    public function probeNextInterface(string $monitor): void
    {
        try {
            $names = $this->getRunningInterfaceNames();
        } catch (\Throwable) {
            $this->resetClient();

            return;
        }

        $others = array_values(array_filter($names, fn ($name) => $name !== $monitor));

        if ($others === []) {
            return;
        }

        $index = (int) Cache::store('file')->get('bandwidth.interface_rr', 0);
        $name = $others[$index % count($others)];
        Cache::store('file')->put('bandwidth.interface_rr', $index + 1, now()->addHour());

        try {
            $kbps = $this->measureFromInterface($name);
        } catch (\Throwable) {
            $this->resetClient();
            $kbps = null;
        }

        $cached = Cache::store('file')->get('bandwidth.interface_kbps', []);
        if (! is_array($cached)) {
            $cached = [];
        }

        $cached[$name] = $kbps;
        Cache::store('file')->put('bandwidth.interface_kbps', $cached, now()->addHour());
    }

    /**
     * @return list<array{name: string, kbps: int|null}>
     */
    public function getInterfaceTrafficSamples(): array
    {
        $monitor = $this->monitorInterfaceName();

        try {
            $kbps = $this->measureFromInterface($monitor);
        } catch (\Throwable) {
            $this->resetClient();
            $kbps = null;
        }

        return $this->interfaceTrafficSnapshot($monitor, $kbps);
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

    /**
     * @return list<array<string, mixed>>
     */
    protected function safeConnections(): array
    {
        try {
            return $this->getConnections();
        } catch (\Throwable) {
            $this->resetClient();

            return [];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     */
    protected function sumConnectionRatesKbps(array $connections): int
    {
        $totalBps = 0;

        foreach ($connections as $conn) {
            $totalBps += (int) ($conn['orig-rate'] ?? 0);
            $totalBps += (int) ($conn['repl-rate'] ?? 0);
        }

        return (int) ceil($totalBps / 1_000);
    }

    /**
     * Live per-user throughput from firewall connection rates (bits/sec → Kbps).
     *
     * @param  list<array<string, mixed>>|null  $connections
     * @return array<int, array{download_kbps: int, upload_kbps: int, total_kbps: int}>
     */
    public function measureUserThroughputKbps(?array $connections = null): array
    {
        try {
            $connections ??= $this->safeConnections();
            $usersByIp = User::whereNotNull('ip_address')
                ->get()
                ->keyBy(fn (User $user) => $this->normalizeIp($user->ip_address));

            $bps = [];

            foreach ($connections as $conn) {
                $src = $conn['src-address'] ?? null;

                if (! $src) {
                    continue;
                }

                $user = $usersByIp->get($this->normalizeIp(explode(':', $src)[0]));

                if (! $user) {
                    continue;
                }

                $bps[$user->id] ??= ['down' => 0, 'up' => 0];
                $bps[$user->id]['up'] += (int) ($conn['orig-rate'] ?? 0);
                $bps[$user->id]['down'] += (int) ($conn['repl-rate'] ?? 0);
            }

            $result = [];

            foreach ($bps as $userId => $rates) {
                $result[$userId] = [
                    'download_kbps' => (int) ceil($rates['down'] / 1_000),
                    'upload_kbps' => (int) ceil($rates['up'] / 1_000),
                    'total_kbps' => (int) ceil(($rates['down'] + $rates['up']) / 1_000),
                ];
            }

            return $result;
        } catch (\Throwable) {
            $this->resetClient();

            return [];
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
