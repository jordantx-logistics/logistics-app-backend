<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Índices para el listado acotado por agencia (SLO de la Fase 3).
     *
     * El listado filtra por `origin_agency_id` **o** `destination_agency_id` y
     * ordena por `id DESC`. Los índices existentes son `(agencia, created_at)`,
     * que no cubren el `ORDER BY id`; estos `(agencia, id)` permiten resolver el
     * rango y el orden con el mismo índice (y en MySQL, el index merge del OR).
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index(['origin_agency_id', 'id']);
            $table->index(['destination_agency_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex(['origin_agency_id', 'id']);
            $table->dropIndex(['destination_agency_id', 'id']);
        });
    }
};
