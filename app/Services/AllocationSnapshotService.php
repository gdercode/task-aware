<?php

namespace App\Services;

use App\Models\BandwidthLog;
use App\Models\Flow;
use App\Models\MikrotikSetting;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AllocationSnapshotService
{
    public function __construct(
        protected AllocationPreviewService $preview,
    ) {}

    private const KEY = 'bandwidth.live_snapshot';

    private const FRESH_SECONDS = 180;

    /**
     * @param  array<string, mixed>  $detection
     * @param  array<string, mixed>  $poolMeasure
     * @param  list<array{name: string, kbps: int|null}>  $interfaceTraffic
     * @param  array<string, mixed>  $allocation
     */
    public function publishLive(
        array $detection,
        array $poolMeasure,
        array $interfaceTraffic,
        array $allocation,
        ?string $warning,
    ): void {
        $allocation['users'] = collect($allocation['users'] ?? [])->map(function ($row) {
            return [
                'user_id' => $row->user->id,
                'score' => $row->score,
                'base_score' => $row->base_score,
                'activity_status' => $row->activity_status,
                'activity_label' => $row->activity_label,
                'task_type' => $row->task_type,
                'is_online' => $row->is_online,
                'share_percent' => $row->share_percent,
                'share_kbps' => $row->share_kbps,
                'kbps_display' => $row->kbps_display,
                'bandwidth' => $row->bandwidth,
                'throughput_down_kbps' => $row->throughput_down_kbps,
                'throughput_up_kbps' => $row->throughput_up_kbps,
                'throughput_total_kbps' => $row->throughput_total_kbps,
                'throughput_display' => $row->throughput_display,
                'usage_percent' => $row->usage_percent ?? 0,
                'impact' => $row->impact ?? 'none',
                'impact_label' => $row->impact_label ?? 'No allocation',
                'impact_detail' => $row->impact_detail ?? '',
                'task_label' => $row->task_label ?? 'General use',
                'task_effect' => $row->task_effect ?? '',
            ];
        })->values()->all();

        $this->write([
            'connected' => true,
            'bandwidth_measure_error' => $warning,
            'detection' => $detection,
            'pool_measure' => $poolMeasure,
            'interface_traffic' => $interfaceTraffic,
            'allocation' => $allocation,
        ]);
    }

    public function publishOffline(): void
    {
        $this->write([
            'connected' => false,
            'bandwidth_measure_error' => null,
            'detection' => null,
            'pool_measure' => null,
            'interface_traffic' => [],
            'allocation' => null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $poolMeasure
     * @param  array<string, mixed>  $allocation
     * @param  list<array{name: string, kbps: int|null}>  $interfaceTraffic
     * @param  array<string, mixed>  $detection
     */
    public function measurementWarning(
        array $poolMeasure,
        array $allocation,
        array $interfaceTraffic,
        array $detection,
    ): ?string {
        $poolKbps = (int) ($poolMeasure['kbps'] ?? 0);

        if (($poolMeasure['interface_error'] ?? null) && $poolKbps === 0) {
            return 'Could not read monitor interface "'.$poolMeasure['interface'].'". '
                .'Pick a valid interface below (see live traffic per interface).';
        }

        if ($allocation['pool_using_fallback'] ?? false) {
            return 'Interface "'.$poolMeasure['interface'].'" shows '
                .$poolMeasure['interface_kbps'].' Kbps; client connections show '
                .$poolMeasure['connection_kbps'].' Kbps — using minimum pool of '
                .config('bandwidth.min_pool_kbps', 64).' Kbps for active users.';
        }

        if ($poolKbps === 0 && ($allocation['total_score'] ?? 0) === 0) {
            return $this->poolZeroMessage($poolMeasure, $interfaceTraffic, $detection);
        }

        if (($poolMeasure['source'] ?? '') === 'connections' && ($poolMeasure['interface_kbps'] ?? 0) === 0) {
            return 'Pool measured from active client connections ('
                .$poolMeasure['connection_kbps'].' Kbps). Interface "'.$poolMeasure['interface']
                .'" shows 0 — consider setting monitor interface to your LAN/WLAN interface.';
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function forDashboard(): array
    {
        $snapshot = $this->read();
        $connected = $snapshot['state'] === 'live';
        $measuredAt = $snapshot['measured_at'];

        $users = User::whereNotNull('ip_address')
            ->withCount(['flows as active_flows_count' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();

        $allocation = $this->rehydrateAllocation($snapshot['allocation'], $users);
        [$activeUsers, $inactiveUsers] = $this->splitUsers($users, $allocation['users'], $connected);

        $stats = [
            'active_users' => $activeUsers->count(),
            'inactive_users' => $inactiveUsers->count(),
            'online_devices' => $allocation['online_count'] ?? 0,
            'offline_devices' => $allocation['offline_count'] ?? 0,
            'activity' => $allocation['activity'] ?? [],
            'pool_kbps' => $allocation['pool_kbps'] ?? 0,
            'total_throughput_kbps' => $allocation['total_throughput_kbps'] ?? 0,
            'total_allocated_kbps' => $allocation['total_allocated_kbps'] ?? 0,
            'usage_of_pool_percent' => $allocation['usage_of_pool_percent'] ?? 0,
            'usage_of_allocated_percent' => $allocation['usage_of_allocated_percent'] ?? 0,
            'users_at_limit' => $allocation['users_at_limit'] ?? 0,
            'headroom_kbps' => $allocation['headroom_kbps'] ?? 0,
            'active_flows' => Flow::where('is_active', true)->count(),
            'total_reports' => BandwidthLog::where('router_connected', true)->count(),
            'total_available_bandwidth' => $connected ? ($allocation['pool_display'] ?? null) : null,
            'total_available_at' => $connected ? $measuredAt : null,
        ];

        return [
            'activeUsers' => $activeUsers,
            'inactiveUsers' => $inactiveUsers,
            'users' => $users,
            'stats' => $stats,
            'mikrotikSettings' => MikrotikSetting::current(),
            'mikrotikConnected' => $connected,
            'bandwidthMeasureError' => $connected ? $snapshot['bandwidth_measure_error'] : null,
            'allocation' => $allocation,
            'detection' => $connected ? $snapshot['detection'] : null,
            'poolMeasure' => $connected ? $snapshot['pool_measure'] : null,
            'interfaceTraffic' => $connected ? $snapshot['interface_traffic'] : [],
            'reportState' => $snapshot['state'],
            'reportUpdatedAt' => $measuredAt,
        ];
    }

    public function isLive(): bool
    {
        return $this->read()['state'] === 'live';
    }

    /**
     * @return array{
     *     connected: bool,
     *     state: string,
     *     measured_at: ?Carbon,
     *     bandwidth_measure_error: ?string,
     *     detection: ?array,
     *     pool_measure: ?array,
     *     interface_traffic: list<array{name: string, kbps: int|null}>,
     *     allocation: ?array
     * }
     */
    public function read(): array
    {
        $raw = Cache::store('file')->get(self::KEY);

        if (! is_array($raw) || empty($raw['measured_at'])) {
            return $this->blank();
        }

        $measuredAt = Carbon::parse($raw['measured_at']);
        $stale = $measuredAt->lt(now()->subSeconds(self::FRESH_SECONDS));
        $connected = (bool) ($raw['connected'] ?? false) && ! $stale;

        $state = 'offline';
        if ($connected) {
            $state = 'live';
        } elseif ($stale) {
            $state = 'waiting';
        }

        return [
            'connected' => $connected,
            'state' => $state,
            'measured_at' => $measuredAt,
            'bandwidth_measure_error' => $raw['bandwidth_measure_error'] ?? null,
            'detection' => $raw['detection'] ?? null,
            'pool_measure' => $raw['pool_measure'] ?? null,
            'interface_traffic' => $raw['interface_traffic'] ?? [],
            'allocation' => $raw['allocation'] ?? null,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    protected function write(array $payload): void
    {
        $payload['measured_at'] = now()->toIso8601String();

        Cache::store('file')->forever(self::KEY, $payload);
    }

    /**
     * @return array{
     *     connected: bool,
     *     state: string,
     *     measured_at: null,
     *     bandwidth_measure_error: null,
     *     detection: null,
     *     pool_measure: null,
     *     interface_traffic: array{},
     *     allocation: null
     * }
     */
    protected function blank(): array
    {
        return [
            'connected' => false,
            'state' => 'waiting',
            'measured_at' => null,
            'bandwidth_measure_error' => null,
            'detection' => null,
            'pool_measure' => null,
            'interface_traffic' => [],
            'allocation' => null,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $allocation
     * @param  Collection<int, User>  $users
     * @return array<string, mixed>
     */
    protected function rehydrateAllocation(?array $allocation, Collection $users): array
    {
        $empty = [
            'pool_kbps' => 0,
            'pool_label' => '0k/0k',
            'pool_display' => '0 Kbps',
            'pool_using_fallback' => false,
            'total_score' => 0,
            'total_throughput_kbps' => 0,
            'total_allocated_kbps' => 0,
            'usage_of_pool_percent' => 0,
            'usage_of_allocated_percent' => 0,
            'users_at_limit' => 0,
            'headroom_kbps' => 0,
            'online_count' => 0,
            'offline_count' => 0,
            'activity' => [],
            'users' => collect(),
        ];

        if ($allocation === null) {
            return $empty;
        }

        $usersById = $users->keyBy('id');
        $rows = collect($allocation['users'] ?? [])->map(function ($row) use ($usersById) {
            $user = $usersById->get($row['user_id'] ?? null);

            if (! $user) {
                return null;
            }

            $row['user'] = $user;
            $impact = $this->preview->describeUsage(
                (int) ($row['throughput_total_kbps'] ?? 0),
                (int) ($row['share_kbps'] ?? 0),
                (string) ($row['activity_status'] ?? 'unknown'),
                $row['task_type'] ?? null,
            );

            return (object) array_merge($row, $impact);
        })->filter()->values();

        $allocation['users'] = $rows;
        $summary = $this->preview->summarizeUsage(
            $rows,
            (int) ($allocation['pool_kbps'] ?? 0),
            (int) ($allocation['total_throughput_kbps'] ?? 0),
            (int) ($allocation['total_allocated_kbps'] ?? 0),
        );

        return array_merge($empty, $allocation, $summary);
    }

    /**
     * @param  Collection<int, User>  $users
     * @param  Collection<int, object>  $rows
     * @return array{0: Collection, 1: Collection}
     */
    protected function splitUsers(Collection $users, Collection $rows, bool $connected): array
    {
        $active = collect();
        $inactive = collect();

        if (! $connected) {
            foreach ($users as $user) {
                if ($user->active_flows_count === 0) {
                    $inactive->push((object) ['user' => $user]);
                }
            }

            return [$active, $inactive];
        }

        $byUser = $rows->keyBy(fn ($row) => $row->user->id);
        $active = $rows
            ->filter(fn ($row) => $row->is_online && $row->share_kbps > 0)
            ->values();

        foreach ($users as $user) {
            $row = $byUser->get($user->id);
            $isGettingBandwidth = $row && $row->is_online && $row->share_kbps > 0;

            if ($isGettingBandwidth) {
                continue;
            }

            $status = $row?->activity_status ?? $user->activity_status;
            $inactive->push((object) [
                'user' => $user,
                'is_online' => (bool) ($row?->is_online ?? false),
                'share_kbps' => 0,
                'kbps_display' => '0 Kbps',
                'activity_status' => $status,
                'throughput_total_kbps' => $row?->throughput_total_kbps ?? 0,
                'throughput_down_kbps' => $row?->throughput_down_kbps ?? 0,
                'throughput_up_kbps' => $row?->throughput_up_kbps ?? 0,
                'throughput_display' => $row?->throughput_display ?? '0 Kbps',
                'usage_percent' => $row?->usage_percent ?? 0,
                'impact' => $row?->impact ?? 'none',
                'impact_label' => $row?->impact_label ?? 'No allocation',
                'impact_detail' => $row?->impact_detail ?? '',
                'task_label' => $row?->task_label ?? null,
                'task_effect' => $row?->task_effect ?? null,
                'offline_reason' => match ($status) {
                    'offline' => 'Device offline',
                    'idle' => 'Idle — no internet use',
                    'low_usage' => 'Low usage — minimal share',
                    default => 'No bandwidth allocated',
                },
            ]);
        }

        return [$active, $inactive];
    }

    /**
     * @param  array<string, mixed>  $poolMeasure
     * @param  list<array{name: string, kbps: int|null}>  $interfaceTraffic
     * @param  array<string, mixed>  $detection
     */
    protected function poolZeroMessage(array $poolMeasure, array $interfaceTraffic, array $detection): string
    {
        $busy = collect($interfaceTraffic)->filter(fn ($row) => ($row['kbps'] ?? 0) > 0)->take(3);
        $detectedUsers = collect($detection['users'] ?? [])->where('detected', true)->count();

        $message = 'Bandwidth pool is 0 Kbps. Interface "'.$poolMeasure['interface'].'" has no traffic right now';

        if (($poolMeasure['connection_kbps'] ?? 0) > 0) {
            $message .= ' (but client connections show '.$poolMeasure['connection_kbps'].' Kbps)';
        }

        $message .= '.';

        if ($busy->isNotEmpty()) {
            $message .= ' Interfaces with traffic now: '
                .$busy->map(fn ($row) => $row['name'].' ('.$row['kbps'].' Kbps)')->implode(', ')
                .' — set Monitor interface to one of these.';
        } elseif ($detectedUsers === 0) {
            $message .= ' No users detected on router — fix user IP addresses first (see detection table above).';
        } else {
            $message .= ' Browse on a client device, then refresh. Or pick the LAN/WLAN interface below.';
        }

        return $message;
    }
}
