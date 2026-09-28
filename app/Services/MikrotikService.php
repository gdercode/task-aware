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
                    $query->equal('target', $row['target']);
                    $query->equal('max-limit', $row['max_limit']);
                    $query->equal('disabled', 'no');
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
     * Queues alone do not stop browsing: MikroTik treats 0/0 as unlimited, and FastTrack
     * bypasses simple queues. A 0% device is dropped in the forward chain and its open
     * connections are removed so the block takes effect immediately.
     *
     * @param  list<array{name: string, target: string, max_limit: string, blocked?: bool}>  $assignments
     * @param  list<array<string, mixed>>  $connections
     * @return array{queues: array<string, bool>, error: ?string}
     */
    public function applyAccessControl(array $assignments, array $connections, bool $updateAllQueues = true): array
    {
        $blockedIps = [];
        $controlledIps = [];

        foreach ($assignments as $index => $row) {
            $ip = $this->hostFromTarget($row['target'] ?? null);
            if ($ip === '') {
                continue;
            }

            $assignments[$index]['target'] = $ip.'/32';
            $controlledIps[$ip] = true;
            if (! empty($row['blocked'])) {
                $blockedIps[$ip] = true;
            }
        }

        try {
            $this->syncAddressList('ta-blocked', array_keys($blockedIps));
            $this->syncAddressList('ta-controlled', array_keys($controlledIps));
            $this->ensureBlockRules();
            $this->ensureFasttrackBypass();
            $this->removeConnectionsFor($blockedIps, $connections);
        } catch (\Throwable $e) {
            $this->resetClient();

            return [
                'queues' => [],
                'error' => $e->getMessage(),
            ];
        }

        $queueRows = $updateAllQueues
            ? $assignments
            : array_values(array_filter($assignments, fn (array $row) => ! empty($row['blocked'])));

        return [
            'queues' => $this->syncQueueLimits($queueRows),
            'error' => null,
        ];
    }

    /**
     * @param  list<string>  $ips
     */
    protected function syncAddressList(string $list, array $ips): void
    {
        $wanted = [];
        foreach ($ips as $ip) {
            $ip = $this->hostFromTarget($ip);
            if ($ip !== '') {
                $wanted[$ip] = true;
            }
        }

        $rows = $this->getClient()->query('/ip/firewall/address-list/print')->read();
        $present = [];

        foreach ($rows as $row) {
            if (($row['list'] ?? '') !== $list) {
                continue;
            }

            $address = $this->hostFromTarget($row['address'] ?? null);
            $ours = ($row['comment'] ?? '') === 'task-aware';

            if ($ours && $address !== '' && ! isset($wanted[$address]) && isset($row['.id'])) {
                $this->removeById('/ip/firewall/address-list/remove', $row['.id']);

                continue;
            }

            if ($address !== '') {
                $present[$address] = true;
            }
        }

        foreach (array_keys($wanted) as $ip) {
            if (isset($present[$ip])) {
                continue;
            }

            $query = new Query('/ip/firewall/address-list/add');
            $query->equal('list', $list);
            $query->equal('address', $ip);
            $query->equal('comment', 'task-aware');
            $this->getClient()->query($query)->read();
        }
    }

    protected function ensureBlockRules(): void
    {
        $rules = $this->getClient()->query('/ip/firewall/filter/print')->read();
        $comments = [];
        foreach ($rules as $rule) {
            if (isset($rule['comment'])) {
                $comments[$rule['comment']] = true;
            }
        }

        $firstId = $rules[0]['.id'] ?? null;
        $this->ensureForwardDrop($comments, 'task-aware-block-src', 'src-address-list', $firstId);
        $this->ensureForwardDrop($comments, 'task-aware-block-dst', 'dst-address-list', $firstId);
    }

    /**
     * @param  array<string, true>  $comments
     */
    protected function ensureForwardDrop(array $comments, string $comment, string $listProperty, ?string $placeBefore): void
    {
        if (isset($comments[$comment])) {
            return;
        }

        $query = new Query('/ip/firewall/filter/add');
        $query->equal('chain', 'forward');
        $query->equal('action', 'drop');
        $query->equal($listProperty, 'ta-blocked');
        $query->equal('comment', $comment);
        if ($placeBefore) {
            $query->equal('place-before', $placeBefore);
        }

        $this->getClient()->query($query)->read();
    }

    protected function ensureFasttrackBypass(): void
    {
        $mangle = $this->getClient()->query('/ip/firewall/mangle/print')->read();
        $marked = false;
        foreach ($mangle as $rule) {
            if (($rule['comment'] ?? '') === 'task-aware-mark') {
                $marked = true;
                break;
            }
        }

        if (! $marked) {
            $query = new Query('/ip/firewall/mangle/add');
            $query->equal('chain', 'prerouting');
            $query->equal('action', 'mark-connection');
            $query->equal('new-connection-mark', 'ta-client');
            $query->equal('passthrough', 'yes');
            $query->equal('src-address-list', 'ta-controlled');
            $query->equal('connection-mark', 'no-mark');
            $query->equal('comment', 'task-aware-mark');
            $this->getClient()->query($query)->read();
        }

        $filters = $this->getClient()->query('/ip/firewall/filter/print')->read();
        foreach ($filters as $rule) {
            if (($rule['action'] ?? '') !== 'fasttrack-connection' || ($rule['disabled'] ?? 'false') === 'true') {
                continue;
            }

            if (($rule['connection-mark'] ?? '') !== '' || ! isset($rule['.id'])) {
                continue;
            }

            $query = new Query('/ip/firewall/filter/set');
            $query->equal('.id', $rule['.id']);
            $query->equal('connection-mark', 'no-mark');
            $this->getClient()->query($query)->read();
        }
    }

    /**
     * @param  array<string, true>  $blockedIps
     * @param  list<array<string, mixed>>  $connections
     */
    protected function removeConnectionsFor(array $blockedIps, array $connections): void
    {
        if ($blockedIps === []) {
            return;
        }

        if ($connections === []) {
            $connections = $this->getConnections();
        }

        foreach ($connections as $conn) {
            $id = $conn['.id'] ?? null;
            if (! is_string($id) || $id === '') {
                continue;
            }

            $src = $this->connectionHost($conn['src-address'] ?? null);
            $dst = $this->connectionHost($conn['dst-address'] ?? null);
            if (! isset($blockedIps[$src]) && ! isset($blockedIps[$dst])) {
                continue;
            }

            try {
                $this->removeById('/ip/firewall/connection/remove', $id);
            } catch (\Throwable) {
                // The connection may already have closed.
            }
        }
    }

    protected function removeById(string $path, string $id): void
    {
        $query = new Query($path);
        $query->equal('.id', $id);
        $this->getClient()->query($query)->read();
    }

    protected function hostFromTarget(?string $target): string
    {
        $target = trim((string) $target);
        if (preg_match('/^(\d{1,3}(?:\.\d{1,3}){3})/', $target, $matches)) {
            return $matches[1];
        }

        return '';
    }

    protected function connectionHost(?string $address): string
    {
        return $this->hostFromTarget($address);
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
