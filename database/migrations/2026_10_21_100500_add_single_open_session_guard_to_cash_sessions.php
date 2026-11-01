<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Un cajero, una sesión abierta: lo garantiza la base.
     *
     * La columna vale `cashier_id` solo con la sesión abierta y `NULL` el resto
     * del tiempo; el índice único admite varios `NULL`, así que no limita el
     * historial. Bloquear «la sesión abierta del cajero» no servía: si no existe
     * no hay fila que bloquear, y los bloqueos de hueco acaban en deadlock.
     */
    public function up(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->unsignedBigInteger('open_cashier_id')
                ->nullable()
                ->virtualAs("CASE WHEN status = 'open' THEN cashier_id END");

            $table->unique('open_cashier_id');
        });
    }

    public function down(): void
    {
        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropUnique(['open_cashier_id']);
        });

        Schema::table('cash_sessions', function (Blueprint $table) {
            $table->dropColumn('open_cashier_id');
        });
    }
};
