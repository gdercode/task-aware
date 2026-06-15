<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bandwidth_logs', function (Blueprint $table) {
            $table->string('available_bandwidth')->nullable()->after('allocated_bandwidth');
        });

        $mbps = (int) config('bandwidth.total_pool_mbps', 100);
        $pool = "{$mbps}M/{$mbps}M";

        DB::table('bandwidth_logs')
            ->whereNull('available_bandwidth')
            ->update(['available_bandwidth' => $pool]);
    }

    public function down(): void
    {
        Schema::table('bandwidth_logs', function (Blueprint $table) {
            $table->dropColumn('available_bandwidth');
        });
    }
};
