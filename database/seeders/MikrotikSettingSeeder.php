<?php

namespace Database\Seeders;

use App\Models\MikrotikSetting;
use Illuminate\Database\Seeder;

class MikrotikSettingSeeder extends Seeder
{
    public function run(): void
    {
        MikrotikSetting::firstOrCreate([], [
            'host' => config('mikrotik.host'),
            'port' => config('mikrotik.port'),
            'monitor_interface' => config('bandwidth.monitor_interface', 'ether1'),
        ]);
    }
}
