<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        foreach (['purchase_drafts', 'purchase_draft_items', 'purchase_voids', 'stock_adjustments', 'weaver_returns', 'weaver_return_items', 'weaver_refunds'] as $table) {
            if (Schema::hasTable($table)) {
                throw new RuntimeException("Review pre-existing {$table} before migration.");
            }
        }
        Schema::create('purchase_drafts', function (Blueprint $t) {
            $t->id();
            $t->string('purchase_no', 40)->unique();
            $t->unsignedInteger('branch_id');
            $t->foreignId('weaver_id')->nullable()->constrained()->restrictOnDelete();
            $t->unsignedInteger('currency_id')->nullable();
            $t->decimal('rate_to_base', 20, 8)->nullable();
            $t->date('purchase_on')->nullable();
            $t->string('notes', 1000)->nullable();
            $t->enum('state', ['draft', 'posted', 'cancelled'])->default('draft');
            $t->unsignedInteger('version')->default(1);
            $t->foreignId('posted_purchase_id')->nullable()->unique()->constrained('weaver_purchases')->restrictOnDelete();
            $t->string('cancel_reason', 500)->nullable();
            $t->unsignedBigInteger('created_by');
            $t->unsignedBigInteger('updated_by');
            $t->timestamps();
            foreach (['branch_id' => 'branches', 'currency_id' => 'currencies', 'created_by' => 'users', 'updated_by' => 'users'] as $col => $parent) {
                $t->foreign($col)->references('id')->on($parent)->restrictOnDelete();
            }
        });
        Schema::create('purchase_draft_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('purchase_draft_id')->constrained()->restrictOnDelete();
            $t->foreignId('carpet_id')->constrained()->restrictOnDelete();
            $t->decimal('unit_price', 18, 2)->nullable();
            $t->unique(['purchase_draft_id', 'carpet_id'], 'uq_draft_carpet');
        });
        Schema::create('purchase_voids', function (Blueprint $t) {
            $t->id();
            $t->foreignId('weaver_purchase_id')->unique()->constrained()->restrictOnDelete();
            $t->date('voided_on');
            $t->string('reason', 500);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('stock_adjustments', function (Blueprint $t) {
            $t->id();
            $t->string('adjustment_no', 40)->unique();
            $t->unsignedInteger('branch_id');
            $t->enum('adjustment_type', ['relocate', 'missing', 'found']);
            $t->foreignId('location_id')->nullable()->constrained('stock_locations')->restrictOnDelete();
            $t->date('adjusted_on');
            $t->string('reason', 500);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('branch_id')->references('id')->on('branches')->restrictOnDelete();
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('weaver_returns', function (Blueprint $t) {
            $t->id();
            $t->string('return_no', 40)->unique();
            $t->foreignId('weaver_purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('from_location_id')->constrained('stock_locations')->restrictOnDelete();
            $t->date('returned_on');
            $t->string('reason', 500);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        Schema::create('weaver_return_items', function (Blueprint $t) {
            $t->id();
            $t->foreignId('weaver_return_id')->constrained()->restrictOnDelete();
            $t->foreignId('weaver_purchase_item_id')->unique()->constrained()->restrictOnDelete();
        });
        Schema::create('weaver_refunds', function (Blueprint $t) {
            $t->id();
            $t->string('refund_no', 40)->unique();
            $t->foreignId('weaver_purchase_id')->constrained()->restrictOnDelete();
            $t->foreignId('account_transaction_id')->unique()->constrained()->restrictOnDelete();
            $t->decimal('amount_in_purchase_currency', 18, 2);
            $t->decimal('purchase_units_per_refund_unit', 20, 8);
            $t->string('reason', 255);
            $t->unsignedBigInteger('created_by');
            $t->timestamp('created_at');
            $t->foreign('created_by')->references('id')->on('users')->restrictOnDelete();
        });
        DB::statement('ALTER TABLE purchase_drafts ADD CONSTRAINT valid_draft_state CHECK (version > 0 AND ((state=\'posted\') = (posted_purchase_id IS NOT NULL)))');
        DB::statement('ALTER TABLE purchase_draft_items ADD CONSTRAINT draft_price CHECK (unit_price IS NULL OR unit_price >= 0)');
        DB::statement("ALTER TABLE stock_adjustments ADD CONSTRAINT adjustment_location CHECK ((adjustment_type='missing') = (location_id IS NULL))");
        DB::statement('ALTER TABLE weaver_refunds ADD CONSTRAINT positive_refund CHECK (amount_in_purchase_currency > 0 AND purchase_units_per_refund_unit > 0)');
        Schema::table('weaver_purchase_items', function (Blueprint $t) {
            $t->index(['carpet_id', 'id'], 'ix_carpet_acquisitions');
            $t->dropUnique('weaver_purchase_items_carpet_id_unique');
            $t->unique(['weaver_purchase_id', 'carpet_id'], 'uq_purchase_carpet');
        });
        Schema::table('stock_events', function (Blueprint $t) {
            $t->foreignId('purchase_void_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('stock_adjustment_id')->nullable()->constrained()->restrictOnDelete();
            $t->foreignId('weaver_return_item_id')->nullable()->unique()->constrained()->restrictOnDelete();
        });
        DB::statement("ALTER TABLE stock_events MODIFY event_type ENUM('purchase','receipt','transfer','purchase_void','adjustment','weaver_return') NOT NULL, MODIFY resulting_status ENUM('purchased','in_stock','registered','missing','returned') NOT NULL, DROP CONSTRAINT event_source");
        DB::statement("ALTER TABLE stock_events ADD CONSTRAINT correction_event_source CHECK (sequence_no > 0 AND
            ((weaver_purchase_item_id IS NOT NULL)+(stock_receipt_id IS NOT NULL)+(stock_transfer_id IS NOT NULL)+(purchase_void_id IS NOT NULL)+(stock_adjustment_id IS NOT NULL)+(weaver_return_item_id IS NOT NULL)=1) AND (
            (event_type='purchase' AND weaver_purchase_item_id IS NOT NULL AND resulting_status='purchased' AND resulting_location_id IS NULL) OR
            (event_type='receipt' AND stock_receipt_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='transfer' AND stock_transfer_id IS NOT NULL AND resulting_status='in_stock' AND resulting_location_id IS NOT NULL) OR
            (event_type='purchase_void' AND purchase_void_id IS NOT NULL AND resulting_status='registered' AND resulting_location_id IS NULL) OR
            (event_type='adjustment' AND stock_adjustment_id IS NOT NULL AND resulting_status IN ('in_stock','missing') AND resulting_location_id IS NOT NULL) OR
            (event_type='weaver_return' AND weaver_return_item_id IS NOT NULL AND resulting_status='returned' AND resulting_location_id IS NULL)))");
        DB::unprepared('CREATE OR REPLACE VIEW v_current_stock AS SELECT c.*, COALESCE(e.sequence_no,0) AS sequence_no, '
            ."COALESCE(e.resulting_status,'registered') AS stock_status, e.resulting_location_id AS location_id, "
            ."CASE WHEN e.resulting_status='registered' THEN c.registration_branch_id ELSE COALESCE(l.branch_id,p.branch_id,c.registration_branch_id) END AS custody_branch_id, e.occurred_on, i.id AS acquisition_item_id "
            .'FROM carpets c LEFT JOIN stock_events e ON e.carpet_id=c.id AND NOT EXISTS '
            .'(SELECT 1 FROM stock_events newer WHERE newer.carpet_id=c.id AND newer.sequence_no>e.sequence_no) '
            .'LEFT JOIN stock_locations l ON l.id=e.resulting_location_id '
            .'LEFT JOIN weaver_purchase_items i ON i.carpet_id=c.id AND NOT EXISTS '
            .'(SELECT 1 FROM weaver_purchase_items newer_i WHERE newer_i.carpet_id=c.id AND newer_i.id>i.id) '
            .'LEFT JOIN weaver_purchases p ON p.id=i.weaver_purchase_id');
        DB::unprepared('CREATE OR REPLACE VIEW v_weaver_purchase_balances AS SELECT p.id AS purchase_id, '
            .'COALESCE(i.total,0) AS total_amount, IF(v.id IS NULL,0,COALESCE(i.total,0)) AS cancelled_amount, '
            .'COALESCE(ret.total,0) AS returned_amount, IF(v.id IS NULL,COALESCE(i.total,0)-COALESCE(ret.total,0),0) AS net_amount, '
            .'COALESCE(a.paid,0) AS paid_amount, COALESCE(f.refunded,0) AS refunded_amount, '
            .'IF(v.id IS NULL,COALESCE(i.total,0)-COALESCE(ret.total,0),0)-COALESCE(a.paid,0)+COALESCE(f.refunded,0) AS remaining_amount '
            .'FROM weaver_purchases p LEFT JOIN purchase_voids v ON v.weaver_purchase_id=p.id '
            .'LEFT JOIN (SELECT weaver_purchase_id,SUM(unit_price) total FROM weaver_purchase_items GROUP BY weaver_purchase_id) i ON i.weaver_purchase_id=p.id '
            .'LEFT JOIN (SELECT x.weaver_purchase_id,SUM(x.amount_in_purchase_currency) paid FROM weaver_payment_allocations x '
            .'JOIN weaver_payments w ON w.id=x.weaver_payment_id WHERE NOT EXISTS (SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) GROUP BY x.weaver_purchase_id) a ON a.weaver_purchase_id=p.id '
            .'LEFT JOIN (SELECT ri.weaver_purchase_id,SUM(ri.unit_price) total FROM weaver_return_items r JOIN weaver_purchase_items ri ON ri.id=r.weaver_purchase_item_id GROUP BY ri.weaver_purchase_id) ret ON ret.weaver_purchase_id=p.id '
            .'LEFT JOIN (SELECT w.weaver_purchase_id,SUM(w.amount_in_purchase_currency) refunded FROM weaver_refunds w WHERE NOT EXISTS (SELECT 1 FROM account_transactions r WHERE r.reversal_of_id=w.account_transaction_id) GROUP BY w.weaver_purchase_id) f ON f.weaver_purchase_id=p.id');
        foreach (['purchase_voids', 'stock_adjustments', 'weaver_returns', 'weaver_return_items', 'weaver_refunds'] as $table) {
            foreach (['UPDATE', 'DELETE'] as $verb) {
                DB::unprepared('CREATE TRIGGER '.$table.'_no_'.strtolower($verb).' BEFORE '.$verb.' ON '.$table." FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Correction records are immutable'");
            }
        }
        DB::unprepared("CREATE TRIGGER draft_no_final_update BEFORE UPDATE ON purchase_drafts FOR EACH ROW BEGIN IF OLD.state <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized drafts are immutable'; END IF; END");
        DB::unprepared("CREATE TRIGGER draft_no_delete BEFORE DELETE ON purchase_drafts FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Cancel drafts instead of deleting them'");
        foreach (['INSERT' => 'NEW', 'UPDATE' => 'OLD', 'DELETE' => 'OLD'] as $verb => $row) {
            DB::unprepared('CREATE TRIGGER draft_item_'.strtolower($verb).' BEFORE '.$verb.' ON purchase_draft_items FOR EACH ROW BEGIN IF (SELECT state FROM purchase_drafts WHERE id='.$row.".purchase_draft_id) <> 'draft' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Finalized draft items are immutable'; END IF; END");
        }
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve correction history. Use a verified backup or reviewed forward migration.');
    }
};
