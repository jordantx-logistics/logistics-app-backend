<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Idempotencia de los jobs del outbox: una fila por (evento, handler).
        // El índice único es la red de seguridad ante dos workers que procesen
        // el mismo evento a la vez (procesamiento at-least-once).
        Schema::create('processed_events', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('outbox_event_id');
            $table->string('handler');
            $table->timestamp('processed_at');

            $table->unique(['outbox_event_id', 'handler']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('processed_events');
    }
};
