<?php

namespace App\Services;
use RouterOS\Client;
use RouterOS\Query;

class MikrotikService
{
    protected $client;

    public function __construct()
    {
        $this->client = new Client([
            'host' => env('MIKROTIK_HOST'),
            'user' => env('MIKROTIK_USER'),
            'pass' => env('MIKROTIK_PASS'),
            'port' => (int) env('MIKROTIK_PORT', 8728),
        ]);
    }

    public function testConnection()
    {
        return $this->client->query('/system/identity/print')->read();
    }

    public function createQueue($name, $target, $maxLimit)
    {
        $query = new Query('/queue/simple/add');
        $query->equal('name', $name);
        $query->equal('target', $target);
        $query->equal('max-limit', $maxLimit);

        return $this->client->query($query)->read();
    }

    public function getConnections()
    {
        return $this->client
            ->query('/ip/firewall/connection/print')
            ->read();
    }

    public function updateQueue($name, $target, $maxLimit)
    {
        // Find existing queue
        $queues = $this->client
            ->query('/queue/simple/print')
            ->read();

        $queueId = null;

        foreach ($queues as $queue) {
            if (($queue['name'] ?? '') === $name) {
                $queueId = $queue['.id'];
                break;
            }
        }

        // Update existing queue
        if ($queueId) {

            $query = new \RouterOS\Query('/queue/simple/set');

            $query->equal('.id', $queueId);
            $query->equal('max-limit', $maxLimit);

            return $this->client->query($query)->read();
        }

        // Create queue if not exists
        return $this->createQueue($name, $target, $maxLimit);
    }


}
