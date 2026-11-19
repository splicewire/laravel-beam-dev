<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('runner_probe')->update(['state' => DB::table('init_probe')->value('value')]);
    }

    public function down(): void {}
};
