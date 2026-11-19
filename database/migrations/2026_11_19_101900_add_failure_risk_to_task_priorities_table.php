<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_priorities', function (Blueprint $table) {
            $table->unsignedTinyInteger('failure_risk')->default(1)->after('urgency');
        });

        $risks = [
            'REAL_TIME' => 5,
            'LEARNING' => 4,
            'COLLABORATION' => 3,
            'DATA_TRANSFER' => 2,
            'STREAMING' => 1,
            'NORMAL' => 2,
            'BULK' => 1,
        ];

        foreach ($risks as $slug => $risk) {
            DB::table('task_priorities')->where('slug', $slug)->update(['failure_risk' => $risk]);
        }
    }

    public function down(): void
    {
        Schema::table('task_priorities', function (Blueprint $table) {
            $table->dropColumn('failure_risk');
        });
    }
};
