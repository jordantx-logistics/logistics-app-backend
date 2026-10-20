<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas de identidad en `users` (Fase 2, paso 1.2).
 *
 * `status` sostiene la invariante «un usuario suspendido/inactivo no se
 * autentica» (07-reglas.md) y `employee_id` el vínculo con RRHH. Los tres
 * contadores (`failed_login_attempts`, `locked_until`) son la base del bloqueo
 * temporal por intentos fallidos. `created_by` es auditoría del alta.
 *
 * `created_by` no lleva FK (apunta a `users`, del mismo contexto): la decisión
 * del bloque es dejarla como columna indexada y revisarla cuando exista el alta
 * de usuarios (paso 2). `06-datos.md` la omite; se añade porque `02-entidades.md`
 * la lista como campo de User.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('status', 20)->default('active')->after('password')->index();
            $table->foreignId('employee_id')->nullable()->after('status')->constrained()->nullOnDelete();
            $table->boolean('must_change_password')->default(false)->after('employee_id');
            $table->unsignedInteger('failed_login_attempts')->default(0)->after('must_change_password');
            $table->timestamp('locked_until')->nullable()->after('failed_login_attempts');
            $table->timestamp('last_login_at')->nullable()->after('locked_until');
            $table->unsignedBigInteger('created_by')->nullable()->after('last_login_at')->index();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['employee_id']);
            $table->dropColumn([
                'status',
                'employee_id',
                'must_change_password',
                'failed_login_attempts',
                'locked_until',
                'last_login_at',
                'created_by',
            ]);
        });
    }
};
