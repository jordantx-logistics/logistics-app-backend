<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidado diario de asistencia (proyección interna de Identity).
 *
 * No es un agregado con identidad propia: la clave natural es
 * `[employee_id, work_date]`, se recalcula por evento y por eso no tiene `id`
 * ni `timestamps` (06-datos.md). Nada la escribe todavía: el caso de uso que la
 * mantiene llega con las marcaciones (paso 3 de la fase).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('attendance_days', function (Blueprint $table) {
            $table->foreignId('employee_id')->constrained();
            $table->date('work_date');
            $table->dateTime('first_in_at')->nullable();
            $table->dateTime('last_out_at')->nullable();
            $table->unsignedInteger('worked_minutes')->default(0);
            $table->unsignedInteger('late_minutes')->default(0);
            $table->string('status', 20)->index();

            $table->primary(['employee_id', 'work_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('attendance_days');
    }
};
