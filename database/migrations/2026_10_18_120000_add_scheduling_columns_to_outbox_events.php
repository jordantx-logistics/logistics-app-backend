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
        Schema::table('outbox_events', function (Blueprint $table) {
            // `available_at` es el "no lo toques antes de": el consumidor lo
            // empuja al futuro cuando un despacho falla (backoff).
            $table->timestamp('available_at')->nullable()->after('status');

            // `processed_at` sella cuándo se publicó el evento en las colas.
            $table->timestamp('processed_at')->nullable()->after('error');

            // El consumidor filtra por estos dos campos en cada pasada.
            $table->index(['status', 'available_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('outbox_events', function (Blueprint $table) {
            $table->dropIndex(['status', 'available_at']);
            $table->dropColumn(['available_at', 'processed_at']);
        });
    }
};
