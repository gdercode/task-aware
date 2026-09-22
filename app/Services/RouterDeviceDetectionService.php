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
            $connectionSource = ['count' => 0, 'error' => $connectionError, 'ips' => []];
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

        $onlineIps = [];
        $ipSources = [];

        foreach ($sources as $sourceName => $source) {
            foreach ($source['ips'] as $ip) {
                $onlineIps[$ip] = true;
                $ipSources[$ip][] = $sourceName;
            }
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
                    ? 'Matched on router ('.implode(', ', $via).')'
                    : $this->notDetectedReason($ip),
            ];
        })->values()->all();

        return [
            'online_ips' => $onlineIps,
            'sources' => $sources,
            'users' => $users,
        ];
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
        return $this->queryIps('/ip/arp/print', function (array $row) {
            return $this->mikrotik->normalizeIp($row['address'] ?? null);
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
            return ['count' => 0, 'error' => $e->getMessage(), 'ips' => []];
        }
    }

    /**
     * @param  list<array<string, mixed>>  $connections
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function ipsFromConnectionRows(array $connections): array
    {
        $ips = [];

        foreach ($connections as $conn) {
            foreach (['src-address', 'dst-address'] as $field) {
                if ($addr = $conn[$field] ?? null) {
                    $ip = $this->mikrotik->normalizeIp(explode(':', $addr)[0]);
                    if ($ip !== '' && $this->isPrivateOrLocalClientIp($ip)) {
                        $ips[$ip] = true;
                    }
                }
            }
        }

        $list = array_keys($ips);
        sort($list);

        return ['count' => count($list), 'error' => null, 'ips' => $list];
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectDhcp(): array
    {
        return $this->queryIps('/ip/dhcp-server/lease/print', function (array $row) {
            if (($row['status'] ?? '') !== 'bound') {
                return '';
            }

            return $this->mikrotik->normalizeIp($row['active-address'] ?? $row['address'] ?? null);
        });
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function collectHotspot(): array
    {
        return $this->queryIps('/ip/hotspot/active/print', function (array $row) {
            return $this->mikrotik->normalizeIp($row['address'] ?? null);
        });
    }

    /**
     * @return array{count: int, error: ?string, ips: list<string>}
     */
    protected function queryIps(string $path, callable $extract): array
    {
        try {
            $client = $this->mikrotik->getRouterClient();
            $ips = [];

            foreach ($client->query($path)->read() as $row) {
                $ip = $extract($row);
                if ($ip !== '') {
                    $ips[$ip] = true;
                }
            }

            $list = array_keys($ips);
            sort($list);

            return ['count' => count($list), 'error' => null, 'ips' => $list];
        } catch (\Throwable $e) {
            return ['count' => 0, 'error' => $e->getMessage(), 'ips' => []];
        }
    }

    protected function isPrivateOrLocalClientIp(string $ip): bool
    {
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return false;
        }

        if (str_starts_with($ip, '127.') || str_starts_with($ip, '169.254.')) {
            return false;
        }

        return ! str_starts_with($ip, '0.');
    }
}
