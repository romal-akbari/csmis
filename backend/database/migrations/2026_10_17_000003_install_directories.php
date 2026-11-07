<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // MariaDB DDL commits implicitly. Validate ALL legacy facts before changing DDL.
        $this->preflight();
        foreach (['provinces', 'districts', 'areas'] as $table) {
            if (! Schema::hasTable($table)) {
                Schema::create($table, function (Blueprint $blueprint) use ($table) {
                    $blueprint->increments('id');
                    if ($table === 'districts') {
                        $blueprint->unsignedInteger('province_id');
                    } elseif ($table === 'areas') {
                        $blueprint->unsignedInteger('district_id');
                    }
                    $blueprint->string('name', 100);
                    $blueprint->string('name_fa', 100)->nullable();
                    $blueprint->enum('status', ['active', 'inactive'])->default('active');
                    $blueprint->timestamps();
                });
            } else {
                foreach (['created_at', 'updated_at'] as $column) {
                    if (! Schema::hasColumn($table, $column)) {
                        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->timestamp($column)->nullable());
                    }
                }
            }
        }
        if (! Schema::hasTable('weavers')) {
            Schema::create('weavers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('phone', 30)->nullable();
                $table->unsignedInteger('area_id')->nullable();
                $table->unsignedBigInteger('manager_employee_id')->nullable();
                $table->date('registration_date');
                $table->text('notes')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->softDeletes();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        } else {
            foreach (['province_id', 'district_id', 'manager_id', 'area_id', 'created_by'] as $column) {
                $this->dropForeignKeys('weavers', $column);
            }
            // Every retained area already determines its district and province.
            foreach (['province_id', 'district_id'] as $column) {
                if (Schema::hasColumn('weavers', $column)) {
                    Schema::table('weavers', fn (Blueprint $table) => $table->dropColumn($column));
                }
            }
            if (Schema::hasColumn('weavers', 'manager_id')) {
                if (Schema::hasColumn('weavers', 'manager_employee_id')) {
                    DB::table('weavers')->whereNull('manager_employee_id')
                        ->update(['manager_employee_id' => DB::raw('manager_id')]);
                    Schema::table('weavers', fn (Blueprint $table) => $table->dropColumn('manager_id'));
                } else {
                    Schema::table('weavers', fn (Blueprint $table) => $table->renameColumn('manager_id', 'manager_employee_id'));
                }
            } elseif (! Schema::hasColumn('weavers', 'manager_employee_id')) {
                Schema::table('weavers', fn (Blueprint $table) => $table->unsignedBigInteger('manager_employee_id')->nullable());
            }
        }
        if (! Schema::hasTable('suppliers')) {
            Schema::create('suppliers', function (Blueprint $table) {
                $table->id();
                $table->string('name', 150);
                $table->string('phone', 30)->nullable();
                $table->string('address', 255)->nullable();
                $table->text('notes')->nullable();
                $table->enum('status', ['active', 'inactive'])->default('active');
                $table->softDeletes();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestamps();
            });
        } elseif (! Schema::hasColumn('suppliers', 'created_by')) {
            Schema::table('suppliers', fn (Blueprint $table) => $table->unsignedBigInteger('created_by')->nullable());
        }
        $this->restrict('districts', 'province_id', 'provinces');
        $this->restrict('areas', 'district_id', 'districts');
        $this->restrict('weavers', 'area_id', 'areas');
        $this->restrict('weavers', 'manager_employee_id', 'employees');
        $this->restrict('weavers', 'created_by', 'users');
        $this->restrict('suppliers', 'created_by', 'users');
        foreach ([['provinces', ['name']], ['districts', ['province_id', 'name']], ['areas', ['district_id', 'name']]] as [$table, $columns]) {
            $name = 'uq_phase2_'.$table.'_name';
            if (! Schema::hasIndex($table, $name)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->unique($columns, $name));
            }
        }
    }

    private function preflight(): void
    {
        // Reference widths must match before any FK or column DDL is attempted.
        // Legacy geography uses INT, while employees/users/business identifiers use BIGINT.
        foreach ([['provinces', 'id', 'int'], ['districts', 'id', 'int'], ['areas', 'id', 'int'],
            ['districts', 'province_id', 'int'], ['areas', 'district_id', 'int'],
            ['employees', 'id', 'bigint'], ['users', 'id', 'bigint'],
            ['weavers', 'area_id', 'int'], ['weavers', 'manager_id', 'bigint'],
            ['weavers', 'manager_employee_id', 'bigint'], ['weavers', 'created_by', 'bigint'],
            ['suppliers', 'created_by', 'bigint']] as [$table, $column, $type]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            $definition = DB::table('information_schema.COLUMNS')->where('TABLE_SCHEMA', DB::getDatabaseName())
                ->where('TABLE_NAME', $table)->where('COLUMN_NAME', $column)->first(['DATA_TYPE', 'COLUMN_TYPE']);
            if ($definition->DATA_TYPE !== $type || ! str_contains($definition->COLUMN_TYPE, 'unsigned')) {
                throw new RuntimeException("Review legacy {$table}.{$column}: expected unsigned {$type} before directory migration.");
            }
        }
        foreach (['provinces' => ['name'], 'districts' => ['province_id', 'name'], 'areas' => ['district_id', 'name']] as $table => $columns) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (DB::table($table)->select($columns)->groupBy($columns)->havingRaw('COUNT(*) > 1')->exists()) {
                throw new RuntimeException("Resolve duplicate legacy {$table} names within their parent before migrating.");
            }
            if (DB::table($table)->whereRaw("TRIM(name) = ''")->orWhereNull('name')->exists()) {
                throw new RuntimeException("Resolve empty legacy {$table} names before migrating.");
            }
        }
        foreach ([['districts', 'province_id', 'provinces'], ['areas', 'district_id', 'districts'], ['weavers', 'area_id', 'areas'], ['weavers', 'manager_id', 'employees'], ['weavers', 'manager_employee_id', 'employees']] as [$table, $column, $parent]) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, $column)) {
                continue;
            }
            if (DB::table($table)->whereNotNull($column)->whereNotIn($column, DB::table($parent)->select('id'))->exists()) {
                throw new RuntimeException("Resolve orphan legacy {$table}.{$column} before migrating.");
            }
        }
        if (! Schema::hasTable('weavers')) {
            return;
        }
        foreach (['province_id', 'district_id'] as $column) {
            if (Schema::hasColumn('weavers', $column)
                && DB::table('weavers')->whereNotNull($column)->whereNull('area_id')->exists()) {
                throw new RuntimeException('Legacy weavers with partial geography need an explicit area assignment before migrating. No area will be guessed.');
            }
        }
        if (Schema::hasColumn('weavers', 'district_id') && DB::table('weavers as w')
            ->join('areas as a', 'a.id', '=', 'w.area_id')->whereNotNull('w.district_id')
            ->whereColumn('w.district_id', '<>', 'a.district_id')->exists()) {
            throw new RuntimeException('Legacy weaver area/district hierarchy is inconsistent.');
        }
        if (Schema::hasColumn('weavers', 'province_id') && DB::table('weavers as w')
            ->join('areas as a', 'a.id', '=', 'w.area_id')->join('districts as d', 'd.id', '=', 'a.district_id')
            ->whereNotNull('w.province_id')->whereColumn('w.province_id', '<>', 'd.province_id')->exists()) {
            throw new RuntimeException('Legacy weaver area/province hierarchy is inconsistent.');
        }
        if (Schema::hasColumn('weavers', 'manager_id') && Schema::hasColumn('weavers', 'manager_employee_id')
            && DB::table('weavers')->whereNotNull('manager_id')->whereNotNull('manager_employee_id')
                ->whereColumn('manager_id', '<>', 'manager_employee_id')->exists()) {
            throw new RuntimeException('Legacy weaver manager assignments conflict.');
        }
    }

    private function dropForeignKeys(string $table, string $column): void
    {
        foreach (Schema::getForeignKeys($table) as $key) {
            if (in_array($column, $key['columns'], true)) {
                Schema::table($table, fn (Blueprint $blueprint) => $blueprint->dropForeign($key['name']));
            }
        }
    }

    private function restrict(string $table, string $column, string $parent): void
    {
        $this->dropForeignKeys($table, $column);
        Schema::table($table, fn (Blueprint $blueprint) => $blueprint->foreign($column, 'fk_phase2_'.$table.'_'.$column)
            ->references('id')->on($parent)->restrictOnDelete());
    }

    public function down(): void
    {
        throw new RuntimeException('Directories preserve imported data. Use a verified backup or a reviewed forward migration.');
    }
};
