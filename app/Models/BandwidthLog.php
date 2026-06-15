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
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}
