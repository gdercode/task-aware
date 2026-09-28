<?php

namespace App\Console\Commands;

use App\Services\RouterDeviceDetectionService;
use Illuminate\Console\Command;

class DiagnoseDeviceDetection extends Command
{
    protected $signature = 'bandwidth:diagnose';

    protected $description = 'Show how MikroTik device detection matches app users';

    public function handle(RouterDeviceDetectionService $detection): int
    {
        $report = $detection->diagnose();

        $this->info('MikroTik device detection');
        $this->newLine();

        foreach ($report['sources'] as $name => $source) {
            $line = sprintf('  %-12s %d IP(s)', ucfirst($name).':', $source['count']);
            if ($source['error']) {
                $line .= ' — error: '.$source['error'];
            }
            $this->line($line);
            if ($source['ips'] !== []) {
                $this->line('    '.implode(', ', $source['ips']));
            }
        }

        $this->newLine();
        $this->info('Devices on the router');

        foreach ($report['devices'] ?? [] as $device) {
            $state = $device['using_internet'] ? 'using the internet' : 'connected, idle';
            $name = $device['registered_name'] ?: 'unregistered';
            $mac = $device['mac'] ?: 'no mac';
            $this->line("  {$device['ip']}  {$mac}  {$name}  ({$state})");
        }

        if (($report['devices'] ?? []) === []) {
            $this->line('  No client devices found.');
        }

        $this->newLine();
        $this->info('User matching');

        foreach ($report['users'] as $user) {
            $status = $user['detected'] ? '<fg=green>YES</>' : '<fg=red>NO</>';
            $this->line("  {$user['name']} ({$user['configured_ip']}) → {$status}");
            $this->line("    {$user['reason']}");
        }

        return self::SUCCESS;
    }
}
