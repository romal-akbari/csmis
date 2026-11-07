<?php

use App\Services\SystemWriteLock;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        app(SystemWriteLock::class)->run(function () {
            Schema::table('company_settings', function (Blueprint $table) {
                $table->unsignedInteger('base_currency_id')->nullable()->change();
                $table->unsignedBigInteger('reset_version')->default(0);
            });
            $tables = ['account_transactions', 'inventory_commands',
                'weaver_purchases', 'weaver_purchase_items', 'stock_receipts',
                'stock_transfers', 'stock_events', 'weaver_payments',
                'weaver_payment_allocations', 'purchase_voids', 'stock_adjustments',
                'weaver_returns', 'weaver_return_items', 'weaver_refunds',
                'purchase_drafts', 'purchase_draft_items'];
            $triggers = DB::table('information_schema.TRIGGERS')
                ->where('TRIGGER_SCHEMA', DB::getDatabaseName())
                ->where('EVENT_MANIPULATION', 'DELETE')
                ->whereIn('EVENT_OBJECT_TABLE', $tables)->get();
            foreach ($triggers as $trigger) {
                $name = $trigger->TRIGGER_NAME;
                $table = $trigger->EVENT_OBJECT_TABLE;
                $original = rtrim($trigger->ACTION_STATEMENT, ';');
                $prefix = "CREATE TRIGGER `{$name}` BEFORE DELETE ON `{$table}` FOR EACH ROW ";
                DB::unprepared("DROP TRIGGER `{$name}`");
                try {
                    DB::unprepared($prefix.'BEGIN IF COALESCE(@csms_system_reset,0) <> 1 '
                        .'THEN '.$original.'; END IF; END');
                } catch (Throwable $error) {
                    DB::unprepared($prefix.$original);
                    throw $error;
                }
            }
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Use a reviewed forward migration or verified backup.');
    }
};
