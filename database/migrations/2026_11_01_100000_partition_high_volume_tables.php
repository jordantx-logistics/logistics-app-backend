<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Src\Shared\Console\Commands\PartitionsMigrateCommand;
use Src\Shared\Services\PartitionedTableSwap;
use Src\Shared\Services\PartitionManager;

/**
 * Fase 9: particiona auditoría y marcaciones, y crea el histórico del outbox.
 *
 * MySQL exige que toda clave única incluya la columna de partición, y prohíbe las FK en
 * tablas particionadas. Por eso `idempotency_key` pasa a una tabla auxiliar sin
 * particionar: `(idempotency_key, created_at)` no protegería de nada, porque cada
 * reintento lleva otro `created_at`. Las FK de marcaciones eran intra-contexto: las
 * reglas de registro ya comprueban empleado y dispositivo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_idempotency_keys', function (Blueprint $table) {
            $table->string('idempotency_key', 64)->primary();
            $table->dateTime('created_at');
        });

        DB::statement('INSERT INTO attendance_idempotency_keys (idempotency_key, created_at)
            SELECT idempotency_key, COALESCE(created_at, CURRENT_TIMESTAMP) FROM attendance_records');

        Schema::create('partition_archives', function (Blueprint $table) {
            $table->id();
            $table->string('table_name', 64);
            $table->string('partition_name', 16);
            $table->string('disk', 32);
            $table->string('path');
            $table->unsignedBigInteger('row_count');
            $table->unsignedBigInteger('bytes');
            $table->char('sha256', 64);
            $table->dateTime('created_at');

            $table->unique(['table_name', 'partition_name']);
        });

        if (DB::getDriverName() !== 'mysql') {
            DB::statement('CREATE TABLE outbox_events_archive AS SELECT * FROM outbox_events WHERE 0');
            DB::statement('CREATE UNIQUE INDEX outbox_events_archive_id_unique ON outbox_events_archive (id)');

            return;
        }

        $this->createOutboxArchive();

        Schema::table('attendance_records', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropForeign(['device_id']);
        });

        foreach (PartitionsMigrateCommand::ALTERATIONS as $table => $alterations) {
            $this->partition($table, $alterations);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('outbox_events_archive');
        Schema::dropIfExists('partition_archives');

        if (DB::getDriverName() === 'mysql') {
            DB::statement('ALTER TABLE audit_logs REMOVE PARTITIONING');
            DB::statement('ALTER TABLE attendance_records REMOVE PARTITIONING');
            DB::statement('ALTER TABLE attendance_records DROP INDEX attendance_records_idempotency_key_index, ADD UNIQUE KEY attendance_records_idempotency_key_unique (idempotency_key)');

            Schema::table('attendance_records', function (Blueprint $table) {
                $table->foreign('employee_id')->references('id')->on('employees');
                $table->foreign('device_id')->references('id')->on('devices')->nullOnDelete();
            });
        }

        Schema::dropIfExists('attendance_idempotency_keys');
    }

    private function createOutboxArchive(): void
    {
        DB::statement('CREATE TABLE outbox_events_archive LIKE outbox_events');
        DB::statement('ALTER TABLE outbox_events_archive
            MODIFY id BIGINT UNSIGNED NOT NULL,
            MODIFY created_at DATETIME NOT NULL,
            DROP PRIMARY KEY,
            ADD PRIMARY KEY (id, created_at)');

        $partitions = app(PartitionManager::class);
        $definition = $partitions->definition(
            CarbonImmutable::now()->subMonths((int) config('partitions.outbox_hot_months')),
            (int) config('partitions.months_ahead'),
        );

        DB::statement("ALTER TABLE outbox_events_archive {$definition}");
    }

    private function partition(string $table, string $alterations): void
    {
        $rows = (int) DB::table($table)->count();

        if ($rows > (int) config('partitions.inline_migration_rows')) {
            Log::warning('partitions.migration_pending', ['table' => $table, 'rows' => $rows]);

            return;
        }

        app(PartitionedTableSwap::class)->run($table, $alterations);

        Schema::dropIfExists("{$table}__old");
    }
};
