<?php

use Illuminate\Support\Facades\Route;
use RouterOS\Client;
use App\Services\MikrotikService;
use App\Services\TrafficDetectionService;
use App\Models\Flow;
use App\Models\User;


Route::get('/test-mikrotik', function (MikrotikService $mikrotik) {
    return $mikrotik->testConnection();
});




Route::get('/', function () {
    return view('welcome');
});

Route::get('/create-queue', function (MikrotikService $mikrotik) {
    $result = $mikrotik->createQueue(
        'test-user',
        '10.10.10.110',
        '2M/2M'
    );
    return $result;
});

Route::get('/test-users', function () {

    return User::all();

});

Route::get('/detect-traffic', function (
    MikrotikService $mikrotik,
    TrafficDetectionService $detector
) {

    $connections = $mikrotik->getConnections();

    foreach ($connections as $conn) {

        $src = $conn['src-address'] ?? null;
        $dst = $conn['dst-address'] ?? 'unknown';

        if (!$src) {
            continue;
        }

        // Remove port if present
        $srcIp = explode(':', $src)[0];

        $user = User::where('ip_address', $srcIp)->first();

        if (!$user) {
            continue;
        }

        $bytes = rand(1000, 100000000);

        $classification = $detector->classify(
            $dst,
            $bytes,
            false
        );

        Flow::create([
            'user_id' => $user->id,
            'task_type' => $classification,
            'priority' => 1,
            'source_ip' => $srcIp,
            'destination' => $dst,
            'classification' => $classification,
            'bytes' => $bytes,
        ]);
    }

    return "Traffic analyzed successfully";
});
