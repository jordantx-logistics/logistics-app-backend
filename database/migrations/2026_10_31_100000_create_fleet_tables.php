<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Choferes, vehículos, rutas troncales, gastos y kilometraje (Fase 6).
     *
     * Usuarios y agencias son de otros contextos: columnas indexadas, sin FK
     * (ADR-0006). Las FK de este archivo son todas intra-Flota.
     */
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->string('license_number', 20)->unique();
            $table->string('license_category', 8);
            $table->date('license_expires_at');
            $table->string('phone', 20)->nullable();
            $table->string('status', 20)->default('active');
            $table->unsignedBigInteger('home_agency_id')->index();
            $table->timestamps();

            $table->index(['status', 'license_expires_at']);
        });

        Schema::create('vehicles', function (Blueprint $table) {
            $table->id();
            $table->string('plate', 10)->unique();
            $table->string('type', 20);
            $table->decimal('capacity_kg', 10, 2);
            $table->decimal('capacity_m3', 10, 2);
            $table->unsignedBigInteger('owner_agency_id')->index();
            $table->string('status', 20)->default('available');
            $table->decimal('odometer_km', 12, 2)->default(0);
            $table->timestamps();

            $table->index(['status', 'owner_agency_id']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique();
            $table->char('ticket_code', 8)->unique();
            $table->unsignedBigInteger('origin_agency_id')->index();
            $table->unsignedBigInteger('destination_agency_id')->index();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('status', 20)->default('planned');
            $table->timestamp('planned_departure_at');
            $table->timestamp('planned_arrival_at')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->decimal('computed_km', 12, 2)->nullable();
            $table->string('cancel_reason', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['status', 'planned_departure_at']);
            $table->index(['driver_id', 'status']);
            $table->index(['vehicle_id', 'status']);
        });

        Schema::create('route_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->unsignedBigInteger('from_agency_id');
            $table->unsignedBigInteger('to_agency_id');
            $table->decimal('distance_km', 8, 2);
            $table->string('status', 20)->default('pending');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['route_id', 'sequence']);
            $table->index(['route_id', 'status']);
        });

        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('driver_id')->nullable()->constrained('drivers')->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained('vehicles')->nullOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->decimal('liters', 8, 2)->nullable();
            $table->decimal('odometer_km', 12, 2)->nullable();
            $table->string('receipt_path', 255)->nullable();
            $table->string('status', 20)->default('registered');
            $table->string('rejection_reason', 255)->nullable();
            $table->unsignedBigInteger('registered_by')->index();
            $table->timestamp('registered_at');
            $table->unsignedBigInteger('reviewed_by')->nullable()->index();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['route_id', 'type']);
            $table->index(['vehicle_id', 'registered_at']);
            $table->index(['status', 'registered_at']);
        });

        Schema::create('mileage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('route_id')->constrained('routes')->cascadeOnDelete();
            $table->foreignId('vehicle_id')->constrained('vehicles');
            $table->decimal('odometer_km', 12, 2);
            $table->timestamp('recorded_at');
            $table->unsignedBigInteger('recorded_by')->index();
            $table->string('photo_path', 255)->nullable();
            $table->string('idempotency_key', 64)->nullable()->unique();
            $table->timestamps();

            $table->index(['route_id', 'recorded_at']);
            $table->index(['vehicle_id', 'recorded_at']);
        });

        // El evento `route.started` busca los embarques de la ruta por esta columna.
        Schema::table('embarks', function (Blueprint $table) {
            $table->index('route_ref');
        });
    }

    public function down(): void
    {
        Schema::table('embarks', function (Blueprint $table) {
            $table->dropIndex(['route_ref']);
        });

        Schema::dropIfExists('mileage_logs');
        Schema::dropIfExists('expenses');
        Schema::dropIfExists('route_segments');
        Schema::dropIfExists('routes');
        Schema::dropIfExists('vehicles');
        Schema::dropIfExists('drivers');
    }
};
