<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // NULL denotes cancellation of an unreceived line; no physical source is invented.
        DB::statement('ALTER TABLE weaver_returns MODIFY from_location_id BIGINT UNSIGNED NULL');
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve return history with a reviewed forward migration.');
    }
};
