<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tablas de la Fase 7: parámetros de negocio, incidencias y exportaciones.
 *
 * Las referencias a órdenes, agencias y usuarios son de otros contextos: columnas
 * indexadas, sin clave foránea (ADR-0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key', 100)->unique();
            $table->string('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->timestamps();
        });

        Schema::create('incidents', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->char('ticket_code', 8);
            $table->unsignedBigInteger('origin_agency_id');
            $table->unsignedBigInteger('destination_agency_id');
            $table->enum('type', ['damage', 'loss', 'delay', 'billing', 'other']);
            $table->enum('priority', ['low', 'normal', 'high', 'urgent'])->default('normal');
            $table->enum('status', ['open', 'in_progress', 'resolved', 'closed'])->default('open');
            $table->text('description');
            $table->unsignedBigInteger('assigned_to')->nullable();
            $table->dateTime('due_at');
            $table->text('resolution_note')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('closed_at')->nullable();
            $table->dateTime('overdue_alerted_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index('order_id');
            $table->index('ticket_code');
            $table->index(['origin_agency_id', 'status']);
            $table->index(['destination_agency_id', 'status']);
            $table->index(['status', 'due_at']);
            $table->index(['assigned_to', 'status']);
        });

        Schema::create('report_exports', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('report', 50);
            $table->enum('format', ['csv', 'xlsx', 'pdf']);
            $table->json('filters');
            $table->enum('status', ['pending', 'processing', 'ready', 'failed'])->default('pending');
            $table->string('path')->nullable();
            $table->unsignedInteger('row_count')->nullable();
            $table->string('error_code', 50)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'id']);
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_exports');
        Schema::dropIfExists('incidents');
        Schema::dropIfExists('settings');
    }
};
