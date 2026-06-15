<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mikrotik_settings', function (Blueprint $table) {
            $table->string('monitor_interface')->default('ether1')->after('port');
        });
    }

    public function down(): void
    {
        Schema::table('mikrotik_settings', function (Blueprint $table) {
            $table->dropColumn('monitor_interface');
        });
    }
};
