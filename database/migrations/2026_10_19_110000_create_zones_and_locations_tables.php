<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Zonas y ubicaciones (Fase 3).
     *
     * Una `zone` agrupa distritos para tarificar por origen/destino y una
     * `location` es un distrito del ubigeo INEI (6 dígitos). La relación es
     * obligatoria: una ubicación pertenece a **una** zona.
     *
     * Los datos se siembran con `UbigeoSeeder` (1 zona por departamento y las
     * 1877 ubicaciones del padrón); las filas son editables después.
     */
    public function up(): void
    {
        Schema::create('zones', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique(); // ZON-01
            $table->string('name', 100);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('locations', function (Blueprint $table) {
            $table->id();

            $table->char('ubigeo', 6)->unique(); // 010101 (INEI)

            $table->string('department', 100);
            $table->string('province', 100);
            $table->string('district', 150);

            // FK dentro del mismo contexto (Logistics/Location): permitida.
            $table->foreignId('zone_id')->constrained('zones');

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // Los listados filtran por zona y por departamento.
            $table->index(['zone_id', 'district']);
            $table->index('department');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
        Schema::dropIfExists('zones');
    }
};
