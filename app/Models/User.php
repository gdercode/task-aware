<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'ip_address',
        'last_traffic_bytes',
        'last_active_at',
        'activity_status',
        'base_score',
        'effective_score',
        'current_task_type',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_active_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function flows()
    {
        return $this->hasMany(Flow::class);
    }

    public function allocations()
    {
        return $this->hasMany(Allocation::class);
    }

    public function bandwidthLogs()
    {
        return $this->hasMany(BandwidthLog::class);
    }

    public function getUserByIp($ip)
    {
        return \App\Models\User::where('ip_address', $ip)->first();
    }

}
