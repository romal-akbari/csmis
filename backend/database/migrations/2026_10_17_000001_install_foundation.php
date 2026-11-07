<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MySQL DDL commits implicitly: reject unreconciled legacy money BEFORE DDL.
        if ((Schema::hasTable('account_transactions') && DB::table('account_transactions')->exists())
            || (Schema::hasTable('accounts') && DB::table('accounts')->where(function ($query) {
                $query->where('opening_balance', '<>', 0)->orWhere('current_balance', '<>', 0);
            })->exists())) {
            throw new RuntimeException('Legacy finance data needs reconciliation before migration.');
        }
        $sql = file_get_contents(database_path('schema/foundation-baseline.sql'));
        foreach (explode(';', $sql) as $statement) {
            if (trim($statement) !== '') {
                DB::unprepared($statement);
            }
        }
        if (Schema::getForeignKeys('users') === []) {
            Schema::table('users', function (Blueprint $table) {
                $table->foreign('employee_id')->references('id')
                    ->on('employees')->restrictOnDelete();
            });
        }
        Schema::create('stock_locations', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique();
            $table->string('name', 120);
            $table->unsignedInteger('branch_id')->nullable();
            $table->unsignedInteger('washing_facility_id')->nullable();
            $table->enum('location_type', ['showroom', 'warehouse', 'shelf', 'washing', 'other']);
            $table->enum('status', ['active', 'inactive'])->default('active');
            $table->timestamps();
            $table->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $table->foreign('washing_facility_id')->references('id')
                ->on('washing_facilities')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE stock_locations ADD CONSTRAINT location_owner '
            .'CHECK ((branch_id IS NOT NULL) + (washing_facility_id IS NOT NULL) = 1)');
        DB::statement('ALTER TABLE stock_locations ADD CONSTRAINT location_kind '
            ."CHECK ((location_type = 'washing') = (washing_facility_id IS NOT NULL))");
        Schema::create('user_washing_facilities', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id');
            $table->unsignedInteger('washing_facility_id');
            $table->primary(['user_id', 'washing_facility_id']);
            $table->foreign('user_id')->references('id')->on('users')->restrictOnDelete();
            $table->foreign('washing_facility_id')->references('id')
                ->on('washing_facilities')->restrictOnDelete();
        });
        Schema::create('company_settings', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('base_currency_id');
            $table->enum('default_locale', ['en', 'fa'])->default('en');
            $table->string('timezone', 60)->default('Asia/Kabul');
            $table->unsignedSmallInteger('reminder_days_before')->default(3);
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
            $table->foreign('base_currency_id')->references('id')
                ->on('currencies')->restrictOnDelete();
            $table->foreign('updated_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE company_settings ADD CONSTRAINT settings_singleton CHECK (id = 1)');
        Schema::table('accounts', function (Blueprint $table) {
            $table->string('code', 30)->nullable()->unique();
        });
        DB::statement('ALTER TABLE exchange_rates MODIFY rate DECIMAL(20,8) NOT NULL');
        DB::statement('ALTER TABLE exchange_rates ADD CONSTRAINT positive_rate CHECK '
            .'(rate > 0 AND base_currency_id <> quote_currency_id)');
        DB::statement('ALTER TABLE currencies ADD CONSTRAINT currency_precision CHECK '
            .'(decimal_places BETWEEN 0 AND 2)');
        Schema::table('account_transactions', function (Blueprint $table) {
            $table->string('idempotency_key', 100)->nullable()->unique();
            $table->char('request_hash', 64)->nullable();
            $table->decimal('rate_to_base', 20, 8)->nullable();
            $table->enum('entry_type', ['opening', 'income', 'expense', 'reversal'])->nullable();
            $table->unsignedBigInteger('reversal_of_id')->nullable()->unique();
            $table->foreign('reversal_of_id')->references('id')
                ->on('account_transactions')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE account_transactions MODIFY amount DECIMAL(18,2) NOT NULL');
        DB::statement('ALTER TABLE account_transactions ADD CONSTRAINT ledger_phase_one '
            .'CHECK (idempotency_key IS NOT NULL AND request_hash IS NOT NULL '
            .'AND rate_to_base > 0 AND entry_type IS NOT NULL AND created_by IS NOT NULL)');
        DB::unprepared('CREATE TRIGGER ledger_no_update BEFORE UPDATE ON account_transactions '
            ."FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
        DB::unprepared('CREATE TRIGGER ledger_no_delete BEFORE DELETE ON account_transactions '
            ."FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Ledger entries are immutable'");
        DB::unprepared('CREATE VIEW v_account_balances AS SELECT a.id AS account_id, '
            .'a.currency_id, COALESCE(SUM(CASE WHEN t.direction = \'in\' '
            .'THEN t.amount ELSE -t.amount END), 0) AS balance FROM accounts a '
            .'LEFT JOIN account_transactions t ON t.account_id = a.id '
            .'GROUP BY a.id, a.currency_id');
    }

    public function down(): void
    {
        throw new RuntimeException('Foundation preserves imported data. Restore a verified '
            .'backup or use a reviewed forward migration; destructive rollback is disabled.');
    }
};
