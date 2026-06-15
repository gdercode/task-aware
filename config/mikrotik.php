<?php

return [

    'host' => env('MIKROTIK_HOST', '192.168.88.1'),

    'user' => env('MIKROTIK_USER', 'admin'),

    'pass' => env('MIKROTIK_PASS', ''),

    'port' => (int) env('MIKROTIK_PORT', 8728),

    'timeout' => (int) env('MIKROTIK_TIMEOUT', 5),

];
