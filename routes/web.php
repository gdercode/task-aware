<?php

use Illuminate\Support\Facades\Route;
use RouterOS\Client;
use App\Services\MikrotikService;
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

