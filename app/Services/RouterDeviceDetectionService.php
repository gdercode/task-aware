<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\Cache;

class RouterDeviceDetectionService
{
    public function __construct(
        protected MikrotikService $mikrotik,
    ) {}

    /**
     * ARP, DHCP, and hotspot only. The firewall connection table is not read here.
     *
     * @return array<string, array{count: int, error: ?string, ips: list<string>}>
     */
    public function presenceSources(): array
    {
        return [
            'arp' => $this->collectArp(),
            'dhcp' => $this->collectDhcp(),
            'hotspot' => $this->collectHotspot(),
        ];
    }

    /**
     * @param  list<array<string, mixed>>|null  $connections
     * @param  array<string, array{count: int, error: ?string, ips: list<string>}>|null  $presence
     * @return array{
     *     online_ips: array<string, true>,
     *     sources: array<string, array{count: int, error: ?string, ips: list<string>}>,
     *     users: list<array<string, mixed>>
     * }
     */
    public function diagnose(?array $connections = null, ?array $presence = null, ?string $connectionError = null): array
    {
        $presence ??= $this->presenceSources();

        if ($connectionError !== null) {
            $connectionSource = ['count' => 0, 'error' => $connectionError, 'ips' => [], 'details' => []];
        } elseif ($connections === null) {
            $connectionSource = $this->collectConnections();
        } else {
            $connectionSource = $this->ipsFromConnectionRows($connections);
        }

        $sources = [
            'arp' => $presence['arp'],
            'connections' => $connectionSource,
            'dhcp' => $presence['dhcp'],
            'hotspot' => $presence['hotspot'],
        ];

        $devices = $this->devicesOnRouter($sources);
        $onlineIps = [];
        $ipSources = [];

        foreach ($devices as $device) {
            if (! ($device['connected'] ?? false)) {
                continue;
            }

            $onlineIps[$device['ip']] = true;
            $ipSources[$device['ip']] = $device['via'];
        }

        $users = User::whereNotNull('ip_address')->orderBy('name')->get()->map(function (User $user) use ($onlineIps, $ipSources) {
            $ip = $this->mikrotik->normalizeIp($user->ip_address);
            $found = isset($onlineIps[$ip]);
            $via = $ipSources[$ip] ?? [];

            return [
                'name' => $user->name,
                'configured_ip' => $ip,
                'detected' => $found,
                'via' => $via,
                'reason' => $found
                    ? 'On the router ('.implode(', ', $via).')'
                    : $this->notDetectedReason($ip),
            ];
        })->values()->all();

        return [
            'online_ips' => $onlineIps,
            'sources' => $sources,
            'users' => $users,
            'devices' => $devices,
        ];
    }

    /**
     * Devices present on a LAN interface (ARP), not the WAN uplink, hotspot-only clients, or remote sites.
     *
     * @param  array<string, array{count: int, error: ?string, ips: list<string>, details?: array<string, array<string, mixed>>}>  $sources
     * @return list<array<string, mixed>>
     */
    protected function devicesOnRouter(array $sources): array
    {
        $routerIps = $this->routerIps();
        $wan = $this->wanEdge();
        $internet = [];

        foreach ($sources['connections']['ips'] ?? [] as $ip) {
            if ($this->isLanClientIp($ip) && ! isset($routerIps[$ip])) {
                $internet[$ip] = true;
            }
        }

        $candidates = [];

        foreach ($sources['arp']['details'] ?? [] as $ip => $detail) {
            if (! $this->isLanClientIp($ip) || isset($routerIps[$ip]) || isset($wan['gateways'][$ip])) {
                continue;
            }

            $interface = (string) ($detail['interface'] ?? '');
            if ($interface !== '' && isset($wan['interfaces'][$interface])) {
                continue;
            }

            $candidates[$ip] = true;
        }

        $knownUsers = User::query()
            ->where(function ($query) {
                $query->whereNotNull('ip_address')->orWhereNotNull('mac_address');
            })
            ->get();
        $byIp = $knownUsers->keyBy(fn (User $user) => $this->mikrotik->normalizeIp($user->ip_address));
        $byMac = $knownUsers
            ->filter(fn (User $user) => $this->normalizeMac($user->mac_address) !== null)
            ->keyBy(fn (User $user) => $this->normalizeMac($user->mac_address));

        $devices = [];

        foreach (array_keys($candidates) as $ip) {
            $via = [];
            $mac = null;
            $hostname = null;

            foreach (['dhcp', 'hotspot', 'arp', 'connections'] as $sourceName) {
                $detail = $sources[$sourceName]['details'][$ip] ?? null;
                $listed = in_array($ip, $sources[$sourceName]['ips'] ?? [], true);

                if ($detail === null && ! $listed && ! ($sourceName === 'connections' && isset($internet[$ip]))) {
                    continue;
                }

                if ($sourceName === 'connections' && ! isset($internet[$ip])) {
                    continue;
                }

                if ($detail !== null || $listed || ($sourceName === 'connections' && isset($internet[$ip]))) {
                    $via[] = $sourceName;
                }

                $mac ??= $this->normalizeMac($detail['mac'] ?? null);
                if ($hostname === null && ! empty($detail['hostname'])) {
                    $hostname = $detail['hostname'];
                }
            }

            $matched = $mac ? $byMac->get($mac) : null;
            $matched ??= $byIp->get($ip);

            if ($matched && $mac && $this->normalizeMac($matched->mac_address) === null && ! $byMac->has($mac)) {
                $matched->mac_address = $mac;
                $matched->save();
                $byMac->put($mac, $matched);
            }

            if ($matched && $mac && $this->normalizeMac($matched->mac_address) === $mac) {
                $currentIp = $this->mikrotik->normalizeIp($matched->ip_address);
                $ipTaken = $byIp->has($ip) && $byIp->get($ip)->id !== $matched->id;

                if ($currentIp !== $ip && ! $ipTaken) {
                    $byIp->forget($currentIp);
                    $matched->ip_address = $ip;
                    $matched->save();
                    $byIp->put($ip, $matched);
                }
            }

            $rateBps = (int) ($sources['connections']['details'][$ip]['rate_bps'] ?? 0);

            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $hostname,
                'via' => $via,
                'using_internet' => isset($internet[$ip]),
                'registered_name' => $matched?->name,
                'user_id' => $matched?->id,
                'connected' => true,
                'using_bandwidth' => $rateBps > 0,
            ];
        }

        return $this->mergeRememberedDevices($devices, $byIp, $byMac);
    }

    /**
     * @param  list<array<string, mixed>>  $devices
     * @param  \Illuminate\Support\Collection<string, User>  $byIp
     * @param  \Illuminate\Support\Collection<string, User>  $byMac
     * @return list<array<string, mixed>>
     */
    protected function mergeRememberedDevices(array $devices, $byIp, $byMac): array
    {
        $memory = Cache::store('file')->get($this->presenceCacheKey(), []);
        if (! is_array($memory)) {
            $memory = [];
        }

        $currentIps = [];
        $seenOnLanAt = now()->toIso8601String();

        foreach ($devices as $index => $device) {
            $currentIps[$device['ip']] = true;
            $previousBandwidthAt = $memory[$device['ip']]['last_bandwidth_at'] ?? null;
            $lastBandwidthAt = ($device['using_bandwidth'] ?? false)
                ? $seenOnLanAt
                : $previousBandwidthAt;

            $devices[$index]['last_bandwidth_at'] = $lastBandwidthAt;
            $memory[$device['ip']] = [
                'ip' => $device['ip'],
                'mac' => $device['mac'],
                'hostname' => $device['hostname'],
                'last_seen_on_lan_at' => $seenOnLanAt,
                'last_bandwidth_at' => $lastBandwidthAt,
            ];
        }

        $cutoff = now()->subDays(7);

        foreach ($memory as $ip => $row) {
            $seenAt = $row['last_seen_on_lan_at'] ?? $row['last_connected_at'] ?? null;
            if ($seenAt === null || \Carbon\Carbon::parse($seenAt)->lt($cutoff)) {
                unset($memory[$ip]);

                continue;
            }

            if (isset($currentIps[$ip])) {
                continue;
            }

            $mac = $this->normalizeMac($row['mac'] ?? null);
            $matched = $mac ? $byMac->get($mac) : null;
            $matched ??= $byIp->get($this->mikrotik->normalizeIp($ip));

            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $row['hostname'] ?? null,
                'via' => [],
                'using_internet' => false,
                'registered_name' => $matched?->name,
                'user_id' => $matched?->id,
                'connected' => false,
                'using_bandwidth' => false,
                'last_bandwidth_at' => $row['last_bandwidth_at'] ?? null,
            ];
        }

        Cache::store('file')->forever($this->presenceCacheKey(), $memory);

        usort($devices, function (array $a, array $b) {
            if (($a['using_bandwidth'] ?? false) !== ($b['using_bandwidth'] ?? false)) {
                return ($a['using_bandwidth'] ?? false) ? -1 : 1;
            }

            if (($a['connected'] ?? false) !== ($b['connected'] ?? false)) {
                return ($a['connected'] ?? false) ? -1 : 1;
            }

            return strcmp((string) ($b['last_bandwidth_at'] ?? ''), (string) ($a['last_bandwidth_at'] ?? ''));
        });

        return $devices;
    }

    protected function presenceCacheKey(): string
    {
        return 'bandwidth.lan_presence';
    }

    protected function notDetectedReason(string $ip): string
    {
        if ($ip === '') {
            return 'No IP configured in Users — edit the user and set their device IP.';
        }

        return "IP {$ip} not seen on router (ARP, DHCP, connections, or hotspot). Update Users → IP to match MikroTik.";
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectArp(): array
    {
        return $this->queryDevices('/ip/arp/print', function (array $row) {
            $ip = $this->mikrotik->normalizeIp($row['address'] ?? null);
            if (! $this->isLanClientIp($ip)) {
                return null;
            }

            $status = strtolower((string) ($row['status'] ?? ''));
            if (in_array($status, ['failed', 'incomplete'], true)) {
                return null;
            }

            return [
                'ip' => $ip,
                'mac' => $this->normalizeMac($row['mac-address'] ?? null),
                'interface' => trim((string) ($row['interface'] ?? '')) ?: null,
            ];
        });
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectConnections(): array
    {
        try {
            return $this->ipsFromConnectionRows($this->mikrotik->getConnections());
        } catch (\Throwable $e) {
            return ['count' => 0, 'error' => $e->getMessage(), 'ips' => [], 'details' => []];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function ipsFromConnectionRows(array $connections): array
    {
        $details = [];

        foreach ($connections as $conn) {
            $addr = $conn['src-address'] ?? null;
            if (! $addr) {
                continue;
            }

            $ip = $this->mikrotik->normalizeIp(explode(':', $addr)[0]);
            if (! $this->isLanClientIp($ip)) {
                continue;
            }

            $rate = (int) ($conn['orig-rate'] ?? 0) + (int) ($conn['repl-rate'] ?? 0);
            $details[$ip] ??= ['ip' => $ip, 'rate_bps' => 0];
            $details[$ip]['rate_bps'] += $rate;
        }

        $list = array_keys($details);
        sort($list);

        return ['count' => count($list), 'error' => null, 'ips' => $list, 'details' => $details];
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectDhcp(): array
    {
        return $this->queryDevices('/ip/dhcp-server/lease/print', function (array $row) {
            if (($row['status'] ?? '') !== 'bound') {
                return null;
            }

            $ip = $this->mikrotik->normalizeIp($row['active-address'] ?? $row['address'] ?? null);
            if (! $this->isLanClientIp($ip)) {
                return null;
            }

            return [
                'ip' => $ip,
                'mac' => $this->normalizeMac($row['mac-address'] ?? null),
                'hostname' => trim((string) ($row['host-name'] ?? '')) ?: null,
            ];
        });
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectHotspot(): array
    {
        return $this->queryDevices('/ip/hotspot/active/print', function (array $row) {
            $ip = $this->mikrotik->normalizeIp($row['address'] ?? null);
            if (! $this->isLanClientIp($ip)) {
                return null;
            }

            $hostname = trim((string) ($row['user'] ?? $row['comment'] ?? ''));

            return [
                'ip' => $ip,
                'mac' => $this->normalizeMac($row['mac-address'] ?? null),
                'hostname' => $hostname !== '' ? $hostname : null,
            ];
        });
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>, details: array<string, array<string, mixed>>}
     */
    protected function queryDevices(string $path, callable $extract): array
    {
        try {
            $details = [];

            foreach ($this->mikrotik->getRouterClient()->query($path)->read() as $row) {
                $device = $extract($row);
                if (! is_array($device) || empty($device['ip'])) {
                    continue;
                }

                $ip = $device['ip'];
                $current = $details[$ip] ?? ['ip' => $ip];

                if (! empty($device['mac'])) {
                    $current['mac'] = $device['mac'];
                }

                if (! empty($device['hostname'])) {
                    $current['hostname'] = $device['hostname'];
                }

                if (! empty($device['interface'])) {
                    $current['interface'] = $device['interface'];
                }

                $details[$ip] = $current;
            }

            $list = array_keys($details);
            sort($list);

            return ['count' => count($list), 'error' => null, 'ips' => $list, 'details' => $details];
        } catch (\Throwable $e) {
            return ['count' => 0, 'error' => $e->getMessage(), 'ips' => [], 'details' => []];
        }
    }

    /**
     * WAN uplink from the active default route, so those neighbors are not listed as LAN devices.
     *
     * @return array{interfaces: array<string, true>, gateways: array<string, true>}
     */
    protected function wanEdge(): array
    {
        $interfaces = [];
        $gateways = [];

        try {
            foreach ($this->mikrotik->getRouterClient()->query('/ip/route/print')->read() as $row) {
                if (($row['dst-address'] ?? '') !== '0.0.0.0/0') {
                    continue;
                }

                $active = $row['active'] ?? true;
                if ($active === 'false' || $active === false) {
                    continue;
                }

                foreach (['gateway', 'immediate-gw'] as $field) {
                    $gateway = (string) ($row[$field] ?? '');
                    if (! str_contains($gateway, '%')) {
                        $ip = $this->mikrotik->normalizeIp($gateway);
                        if ($this->isLanClientIp($ip)) {
                            $gateways[$ip] = true;
                        }

                        continue;
                    }

                    [$address, $interface] = explode('%', $gateway, 2);
                    $interface = trim($interface);
                    if ($interface !== '') {
                        $interfaces[$interface] = true;
                    }

                    $ip = $this->mikrotik->normalizeIp($address);
                    if ($this->isLanClientIp($ip)) {
                        $gateways[$ip] = true;
                    }
                }
            }
        } catch (\Throwable) {
            return ['interfaces' => [], 'gateways' => []];
        }

        return ['interfaces' => $interfaces, 'gateways' => $gateways];
    }

    /**
     * @return array<string, true>
     */
    protected function routerIps(): array
    {
        try {
            $ips = [];

            foreach ($this->mikrotik->getRouterClient()->query('/ip/address/print')->read() as $row) {
                $address = explode('/', (string) ($row['address'] ?? ''))[0];
                $ip = $this->mikrotik->normalizeIp($address);
                if ($ip !== '') {
                    $ips[$ip] = true;
                }
            }

            return $ips;
        } catch (\Throwable) {
            return [];
        }
    }

    protected function normalizeMac(?string $mac): ?string
    {
        $mac = strtoupper(trim((string) $mac));

        if (! preg_match('/^[0-9A-F]{2}(:[0-9A-F]{2}){5}$/', $mac)) {
            return null;
        }

        return $mac;
    }

    protected function isLanClientIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            return false;
        }

        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
        ) === false;
    }
}
