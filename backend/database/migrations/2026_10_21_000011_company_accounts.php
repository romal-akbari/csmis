<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_accounts', function (Blueprint $t) {
            $t->unsignedBigInteger('user_id');
            $t->foreignId('account_id')->constrained()->restrictOnDelete();
            $t->primary(['user_id', 'account_id']);
            $t->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
        });
        DB::statement('INSERT INTO user_accounts (user_id,account_id) SELECT DISTINCT ub.user_id,a.id FROM user_branches ub JOIN accounts a ON a.branch_id=ub.branch_id');
        Schema::create('document_sequences', function (Blueprint $t) {
            $t->string('kind', 40)->primary();
            $t->unsignedBigInteger('last_number')->default(0);
        });
    }

    public function down(): void
    {
        throw new RuntimeException('Preserve account assignments and document numbering. Use a reviewed forward migration.');
    }
};
