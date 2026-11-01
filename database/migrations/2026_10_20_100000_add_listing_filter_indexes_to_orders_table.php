<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices de los filtros del listado (cierre de Fase 3).
     *
     * El listado ordena por `id DESC` y puede filtrar por estado, remitente o
     * destinatario. Los índices existentes estaban pensados para ordenar por
     * `created_at`; estos cubren el orden real de la consulta.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['status', 'id']);
            $table->index(['sender_id', 'id']);
            $table->index(['receiver_id', 'id']);
            // El listado acotado por agencia filtra `status` y ordena por `id`:
            // el compuesto deja resolver igualdad + orden en el mismo índice.
            $table->index(['origin_agency_id', 'status', 'id']);
            $table->index(['destination_agency_id', 'status', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['status', 'id']);
            $table->dropIndex(['sender_id', 'id']);
            $table->dropIndex(['receiver_id', 'id']);
            $table->dropIndex(['origin_agency_id', 'status', 'id']);
            $table->dropIndex(['destination_agency_id', 'status', 'id']);
        });
    }
};
