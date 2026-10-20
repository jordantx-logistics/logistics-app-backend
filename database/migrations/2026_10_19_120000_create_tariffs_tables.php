<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Tarifarios y tramos (Fase 3).
     *
     * Un tarifario es una regla de precio por servicio y alcance:
     * `national` (sin origen/destino), `zone_pair` (par de zonas) o
     * `district_pair` (par de ubicaciones). Gana el más específico y, a igual
     * alcance, el de mayor `priority` y el más reciente.
     *
     * `valid_to` nullable = sin caducidad. Las FK de zonas/ubicaciones son del
     * propio contexto (`Logistics/Tariff` ↔ `Logistics/Location`), permitidas
     * por ADR-0006.
     */
    public function up(): void
    {
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();

            $table->string('code', 30)->unique();
            $table->string('name', 150);

            $table->string('service_type', 20); // standard | express
            $table->string('scope_type', 20);   // national | zone_pair | district_pair

            // Zonas y ubicaciones viven en `Logistics/Location`: columnas
            // indexadas sin FK (ADR-0006), validadas por el puerto
            // `LocationDirectory` en `TariffRequest`.
            $table->unsignedBigInteger('origin_zone_id')->nullable()->index();
            $table->unsignedBigInteger('destination_zone_id')->nullable()->index();
            $table->unsignedBigInteger('origin_location_id')->nullable()->index();
            $table->unsignedBigInteger('destination_location_id')->nullable()->index();

            $table->decimal('base_price', 12, 2);
            $table->decimal('price_per_kg', 12, 2);
            $table->decimal('price_per_m3', 12, 2)->default(0);
            $table->decimal('min_charge', 12, 2);
            $table->decimal('max_weight_kg', 8, 3);
            $table->unsignedInteger('volumetric_factor')->default(200); // kg/m³
            $table->integer('priority')->default(0);

            $table->date('valid_from');
            $table->date('valid_to')->nullable();
            $table->boolean('is_active')->default(true);

            // Referencia a `users` (Identity): indexada, sin FK (ADR-0006).
            $table->unsignedBigInteger('created_by')->nullable()->index();

            $table->timestamps();

            // Resolución por servicio + alcance + vigencia.
            $table->index(['service_type', 'scope_type', 'is_active', 'valid_from']);
            $table->index(['origin_zone_id', 'destination_zone_id']);
            $table->index(['origin_location_id', 'destination_location_id']);
        });

        Schema::create('tariff_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tariff_id')->constrained('tariffs')->cascadeOnDelete();

            $table->decimal('from_kg', 8, 3);
            $table->decimal('to_kg', 8, 3);
            $table->decimal('fixed_fee', 12, 2)->default(0);
            $table->decimal('price_per_kg', 12, 2)->default(0);

            $table->timestamps();

            $table->index(['tariff_id', 'from_kg']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tariff_brackets');
        Schema::dropIfExists('tariffs');
    }
};
