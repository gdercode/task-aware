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

    /*
    |--------------------------------------------------------------------------
    | Link capacity and congestion
    |--------------------------------------------------------------------------
    |
    | Congestion is demand compared with this capacity. Leave the capacity at 0
    | to use the observed pool (the recent peak on the monitor interface).
    | When two or more devices are active and use reaches the threshold, task
    | priority and urgency outweigh institutional role, and critical tasks are
    | protected.
    |
    */

    'link_capacity_kbps' => (int) env('BANDWIDTH_LINK_CAPACITY_KBPS', 0),

    'congestion_percent' => (int) env('BANDWIDTH_CONGESTION_PERCENT', 70),

    'calm_task_weight' => (float) env('BANDWIDTH_CALM_TASK_WEIGHT', 0.35),

    'congested_task_weight' => (float) env('BANDWIDTH_CONGESTED_TASK_WEIGHT', 0.65),

    'critical_floor_kbps' => (int) env('BANDWIDTH_CRITICAL_FLOOR_KBPS', 512),

    'critical_budget_percent' => (int) env('BANDWIDTH_CRITICAL_BUDGET_PERCENT', 70),

    'bulk_cap_percent' => (int) env('BANDWIDTH_BULK_CAP_PERCENT', 10),

    'streaming_cap_percent' => (int) env('BANDWIDTH_STREAMING_CAP_PERCENT', 20),

];
