<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MikrotikSetting extends Model
{
    protected $fillable = [
        'host',
        'port',
    ];

    protected function casts(): array
    {
        return [
            'port' => 'integer',
        ];
    }

    public static function current(): self
    {
        return static::firstOrCreate([], [
            'host' => config('mikrotik.host'),
            'port' => config('mikrotik.port'),
        ]);
    }
}
