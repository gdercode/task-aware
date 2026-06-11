<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Flow extends Model
{
    protected $fillable = [
        'user_id',
        'task_type',
        'priority',
        'source_ip',
        'destination',
        'classification',
        'bytes',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }



}
