<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement('ALTER TABLE shops MODIFY access_token TEXT');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE shops MODIFY access_token VARCHAR(255)');
    }
};
