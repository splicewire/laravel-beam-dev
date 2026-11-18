<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('runner_probe', function (Blueprint $table): void {
            $table->string('state');
        });
        DB::table('runner_probe')->insert(['state' => 'migration-ready']);
    }

    public function down(): void
    {
        Schema::dropIfExists('runner_probe');
    }
};
