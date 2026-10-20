<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ajusta el pivote `agency_user` al diseño de Identity (Fase 2, paso 1.2):
 * añade `is_primary`, pasa a PK compuesta `[user_id, agency_id]` y **quita las
 * claves foráneas**.
 *
 * Las FK se eliminan a propósito: `agencies` es otro contexto de código
 * (`src/Organization`) y ADR-0006 prohíbe las claves foráneas entre contextos;
 * el pivote es el punto de contacto usuario ↔ agencia, así que se queda con
 * columnas indexadas y la existencia de cada lado se valida en la Rule del caso
 * de uso (igual que `orders.sender_id` o `payments.order_id`). El pivote se
 * llama `agency_user` (y no `user_agency`, como decía el doc) porque es la
 * convención de Eloquent y ya está en producción de la Fase 1.
 *
 * `id` desaparece: el par `[user_id, agency_id]` es la clave natural (una fila
 * por relación) y 06-datos.md pide la PK compuesta. Nada del código lo usaba.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agency_user', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropForeign(['agency_id']);
            $table->dropUnique(['user_id', 'agency_id']);

            // MySQL no deja soltar la PK mientras `id` siga siendo AUTO_INCREMENT
            // (error 1075), así que primero se le quita el autoincremento.
            $table->unsignedBigInteger('id')->change();

            $table->dropPrimary();
            $table->dropColumn('id');

            $table->boolean('is_primary')->default(false)->after('agency_id');
            $table->primary(['user_id', 'agency_id']);
            // Invariante «una sola agencia primaria por usuario»: el índice da
            // soporte a la consulta y el dominio lo valida en su Rule.
            $table->index(['user_id', 'is_primary']);
        });
    }

    public function down(): void
    {
        Schema::table('agency_user', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'is_primary']);
            $table->dropPrimary();
            $table->dropColumn('is_primary');
        });

        Schema::table('agency_user', function (Blueprint $table) {
            $table->id();
            $table->unique(['user_id', 'agency_id']);
        });

        Schema::table('agency_user', function (Blueprint $table) {
            $table->foreign('user_id')->references('id')->on('users')->cascadeOnDelete();
            $table->foreign('agency_id')->references('id')->on('agencies')->cascadeOnDelete();
        });
    }
};
