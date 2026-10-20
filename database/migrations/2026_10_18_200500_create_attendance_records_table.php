<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marcaciones de asistencia: tabla de alto volumen, append-only salvo la
 * anulación (`status = voided`, por eso lleva `updated_at`).
 *
 * La idempotencia es por `idempotency_key` único (regla `ATTENDANCE_DUPLICATE`
 * de 07-reglas.md), no por la terna `(employee_id, type, marked_at)` del
 * resumen del roadmap: dos marcaciones legítimas del mismo tipo pueden caer en
 * el mismo segundo y la terna tampoco distingue el reintento offline.
 *
 * `latitude`/`longitude` (no `geo_lat`/`geo_lng`) son los nombres canónicos:
 * viajan tal cual en el contrato HTTP. `agency_id` es indexada y sin FK porque
 * `agencies` es de otro contexto (ADR-0006); el resto son FK intra-contexto.
 *
 * Fase 9 (particionado): MySQL exige que todo índice único incluya la columna
 * de partición, así que ese día la PK pasa a `[id, created_at]` y el único a
 * `[idempotency_key, created_at]`. La columna `created_at` ya existe para eso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('type', 10);
            $table->dateTime('marked_at')->index();
            $table->string('source', 20);
            $table->foreignId('device_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('latitude', 10, 7)->nullable();
            $table->decimal('longitude', 10, 7)->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('status', 20)->default('valid')->index();
            $table->string('idempotency_key', 64)->unique();
            $table->timestamps();

            $table->index(['employee_id', 'marked_at']);
            $table->index(['agency_id', 'marked_at']);
            $table->index(['status', 'marked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_records');
    }
};
