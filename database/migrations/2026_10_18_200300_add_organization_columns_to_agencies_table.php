<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Columnas organizacionales de `agencies` (Fase 2, paso 1.2).
 *
 * Identity es el dueño canónico del maestro de agencias (la jerarquía y el
 * ámbito de datos se apoyan en él), pero el módulo de código sigue siendo
 * `src/Organization`: por eso la FK `parent_agency_id → agencies` es
 * intra-contexto y sí se declara, mientras que las referencias de otras tablas
 * a `agencies` van sin FK (ADR-0006).
 *
 * `type` nace con `default('branch')` para que el alta de agencias actual
 * (`POST /api/v1/organization/agencies`, que no envía el campo) siga
 * funcionando sin tocar el contrato. Exponer los campos nuevos en la API es
 * trabajo del bloque de agencias, no de esta migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->string('type', 20)->default('branch')->after('name')->index();
            $table->foreignId('parent_agency_id')->nullable()->after('type')->constrained('agencies')->nullOnDelete();
            $table->string('province', 100)->nullable()->after('district')->index();
            $table->string('department', 100)->nullable()->after('province')->index();
            $table->string('timezone', 40)->default('America/Lima')->after('phone');
            $table->date('opened_at')->nullable()->after('is_active');

            $table->index('name');
            $table->index('district');
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropIndex(['name']);
            $table->dropIndex(['district']);
            $table->dropForeign(['parent_agency_id']);
            $table->dropColumn(['type', 'parent_agency_id', 'province', 'department', 'timezone', 'opened_at']);
        });
    }
};
