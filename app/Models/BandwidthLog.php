<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class BandwidthLog extends Model
{
    protected $fillable = [
        'user_id',
        'task_type',
        'importance_score',
        'allocated_bandwidth',
        'available_bandwidth',
        'router_connected',
    ];

    protected function casts(): array
    {
        return [
            'router_connected' => 'boolean',
        ];
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
