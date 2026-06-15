<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedBigInteger('last_traffic_bytes')->default(0)->after('ip_address');
            $table->timestamp('last_active_at')->nullable()->after('last_traffic_bytes');
            $table->string('activity_status', 32)->default('unknown')->after('last_active_at');
            $table->unsignedSmallInteger('base_score')->default(0)->after('activity_status');
            $table->unsignedSmallInteger('effective_score')->default(0)->after('base_score');
            $table->string('current_task_type')->nullable()->after('effective_score');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn([
                'last_traffic_bytes',
                'last_active_at',
                'activity_status',
                'base_score',
                'effective_score',
                'current_task_type',
            ]);
        });
    }
};
