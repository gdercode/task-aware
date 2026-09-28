<?php

namespace App\Services;

use App\Models\User;

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
     * LAN clients that are on the router: internet flows, DHCP leases, or hotspot sessions.
     * Remote website addresses are not devices.
     *
     * @param  array<string, array{count: int, error: ?string, ips: list<string>, details?: array<string, array<string, mixed>>}>  $sources
     * @return list<array<string, mixed>>
     */
    protected function devicesOnRouter(array $sources): array
    {
        $routerIps = $this->routerIps();
        $internet = [];

        foreach ($sources['connections']['ips'] ?? [] as $ip) {
            if ($this->isLanClientIp($ip) && ! isset($routerIps[$ip])) {
                $internet[$ip] = true;
            }
        }

        $candidates = $internet;

        foreach (['dhcp', 'hotspot'] as $sourceName) {
            foreach ($sources[$sourceName]['details'] ?? [] as $ip => $detail) {
                if ($this->isLanClientIp($ip) && ! isset($routerIps[$ip])) {
                    $candidates[$ip] = true;
                }
            }
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

            $devices[] = [
                'ip' => $ip,
                'mac' => $mac,
                'hostname' => $hostname,
                'via' => $via,
                'using_internet' => isset($internet[$ip]),
                'registered_name' => $matched?->name,
                'user_id' => $matched?->id,
            ];
        }

        usort($devices, function (array $a, array $b) {
            if ($a['using_internet'] !== $b['using_internet']) {
                return $a['using_internet'] ? -1 : 1;
            }

            return strcmp($a['ip'], $b['ip']);
        });

        return $devices;
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
        $ips = [];

        $details = [];

        foreach ($connections as $conn) {
            $addr = $conn['src-address'] ?? null;
            if (! $addr) {
                continue;
            }

            $ip = $this->mikrotik->normalizeIp(explode(':', $addr)[0]);
            if ($this->isLanClientIp($ip)) {
                $details[$ip] = ['ip' => $ip];
            }
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
