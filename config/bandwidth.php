<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Monitored Interface
    |--------------------------------------------------------------------------
    |
    | MikroTik interface used to measure live incoming/outgoing bandwidth.
    | The measured rate becomes the dynamic pool for each allocation cycle.
    |
    */

    'monitor_interface' => env('MIKROTIK_MONITOR_INTERFACE', 'ether1'),

    /*
    |--------------------------------------------------------------------------
    | Activity thresholds
    |--------------------------------------------------------------------------
    |
    | Bytes transferred between sync cycles determine whether a user is actively
    | using the internet, lightly using it, or idle. Idle/offline users receive
    | no bandwidth; low-usage users get a minimized score (role weight only).
    |
    */

    'idle_seconds' => (int) env('BANDWIDTH_IDLE_SECONDS', 90),

    'low_usage_bytes' => (int) env('BANDWIDTH_LOW_USAGE_BYTES', 256),

    'active_usage_bytes' => (int) env('BANDWIDTH_ACTIVE_USAGE_BYTES', 1024),

    /*
    |--------------------------------------------------------------------------
    | Minimum pool (Kbps)
    |--------------------------------------------------------------------------
    |
    | When the monitor interface reports 0 Kbps but users are online and active,
    | use this floor so allocations can still be calculated on low/slow links.
    |
    */

    'min_pool_kbps' => (int) env('BANDWIDTH_MIN_POOL_KBPS', 64),

];
