<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Empleados de RRHH (`spec/04-modulos/identity/02-entidades.md`).
 *
 * Separa *empleado* (persona que trabaja) de *usuario de sistema*: el vínculo
 * opcional vive en `users.employee_id` (una persona puede tener usuario y un
 * usuario puede no representar a ningún empleado). Se guarda como FK porque
 * ambas tablas son del mismo contexto.
 *
 * `person_id` apunta a `persons` (Core) y `agency_id` a `agencies`
 * (Organization): referencias indexadas **sin FK**, por ADR-0006.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('person_id')->nullable()->index();
            $table->string('document_type', 20);
            $table->string('document_number', 50)->unique();
            $table->string('first_name', 100);
            $table->string('last_name', 100);
            $table->string('email', 150)->nullable();
            $table->string('phone', 20)->nullable();
            $table->string('position', 80)->index();
            $table->unsignedBigInteger('agency_id')->index();
            $table->date('hired_at');
            $table->date('terminated_at')->nullable();
            $table->string('status', 20)->index();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
            $table->index(['status', 'hired_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employees');
    }
};
