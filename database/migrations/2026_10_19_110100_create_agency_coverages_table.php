<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cobertura de una agencia destino (`spec/04-modulos/core-logistica/02-entidades.md`).
     *
     * `agency_id` apunta a `Organization` (otro contexto): columna indexada sin
     * FK (ADR-0006) y se valida con `AgencyDirectory`. `location_id` es del propio
     * contexto y sí lleva FK.
     */
    public function up(): void
    {
        Schema::create('agency_coverages', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('location_id')->constrained('locations');

            $table->boolean('allows_pickup')->default(true);
            $table->boolean('allows_delivery')->default(false);
            $table->decimal('delivery_surcharge', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);

            $table->timestamps();

            $table->unique(['agency_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agency_coverages');
    }
};
