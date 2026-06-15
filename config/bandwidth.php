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

];
