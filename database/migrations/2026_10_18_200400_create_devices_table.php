<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dispositivos autorizados a autenticarse con token (apps/PDA).
 *
 * `device_uuid` identifica el equipo físico: si el mismo dispositivo pide un
 * token otra vez, se reutiliza la fila (único `[user_id, device_uuid]`) en vez
 * de acumular duplicados. `status` dice *si* está revocado y `revoked_at`
 * *cuándo* —el panel de sesiones muestra ambos—, mientras que `last_seen_at` se
 * refresca en cada petición con token.
 *
 * `user_id → users` es intra-contexto (los usuarios son de Identity), así que
 * lleva FK con borrado en cascada; `employees` y `agencies` son las referencias
 * sin FK por ADR-0006.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('device_name', 120);
            $table->string('platform', 20)->index();
            $table->string('device_uuid', 100);
            $table->string('push_token', 255)->nullable();
            $table->string('app_version', 30)->nullable();
            $table->string('status', 20)->default('active')->index();
            $table->timestamp('last_seen_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'device_uuid']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
