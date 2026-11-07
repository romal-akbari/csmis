<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // SQL CHECK accepts UNKNOWN. Explicit null pairing complements positive sizes.
        if (DB::table('carpets')->whereRaw('(length_cm IS NULL) <> (width_cm IS NULL)')->exists()) {
            throw new RuntimeException('Reconcile incomplete carpet dimensions before adding the pair constraint.');
        }
        DB::statement('ALTER TABLE carpets ADD CONSTRAINT carpet_dimension_pair CHECK ((length_cm IS NULL) = (width_cm IS NULL))');
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve inventory constraints with a reviewed forward migration.');
    }
};
