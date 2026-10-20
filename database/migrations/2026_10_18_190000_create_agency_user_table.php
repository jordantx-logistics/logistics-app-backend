<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Ámbito de agencia del usuario (`spec/02-objetivo/04-autorizacion.md`).
 *
 * La tabla `users` vive fuera de `src/` (es scaffolding de Laravel), así que la
 * relación usuario ↔ agencia no cruza dos contextos de `src/`: es la excepción
 * que el propio spec contempla (`User::agencies()` en el paso 3.4 de la Fase 1).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agency_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('agency_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['user_id', 'agency_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_user');
    }
};
