<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Eventos ya aplicados de los lotes de las apps móviles. Un reintento con el mismo
 * `event_id` no repite el efecto, aunque la operación ya no fuera válida en el estado
 * actual de la entidad. `user_id` referencia otro contexto: sin clave foránea (ADR-0006).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sync_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('event_id')->unique();
            $table->string('event_type', 40);
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->dateTime('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sync_events');
    }
};
