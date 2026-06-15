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

    'idle_seconds' => (int) env('BANDWIDTH_IDLE_SECONDS', 45),

    'low_usage_bytes' => (int) env('BANDWIDTH_LOW_USAGE_BYTES', 4096),

    'active_usage_bytes' => (int) env('BANDWIDTH_ACTIVE_USAGE_BYTES', 16384),

];
