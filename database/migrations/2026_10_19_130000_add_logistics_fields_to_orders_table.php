<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Campos de la Fase 3 en la orden: reparto, servicio, snapshot de precio y
     * auditoría de estado (`spec/04-modulos/core-logistica/02-entidades.md`).
     *
     * `tariff_id` y `delivery_location_id` apuntan a otros contextos
     * (`Logistics/Tariff`, `Logistics/Location`): columnas indexadas sin FK
     * (ADR-0006) y se validan por puerto.
     *
     * `pricing_snapshot` guarda el desglose completo, no solo el total: así se
     * puede auditar el precio aunque el tarifario cambie después.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->decimal('chargeable_weight_kg', 8, 3)->nullable()->after('volume_m3');
            $table->string('service_type', 20)->default('standard')->after('declared_value');
            $table->string('payment_responsibility', 10)->default('sender')->after('service_type');

            $table->unsignedBigInteger('tariff_id')->nullable()->index()->after('payment_responsibility');
            $table->json('pricing_snapshot')->nullable()->after('tariff_id');

            $table->string('delivery_type', 20)->default('agency_pickup')->after('status');
            $table->unsignedBigInteger('delivery_location_id')->nullable()->index()->after('delivery_type');
            $table->string('recipient_address', 255)->nullable()->after('delivery_location_id');
            $table->timestamp('delivery_scheduled_at')->nullable()->after('recipient_address');
            $table->boolean('requires_signature')->default(false)->after('delivery_scheduled_at');
            $table->unsignedInteger('delivery_attempts')->default(0)->after('requires_signature');

            $table->timestamp('confirmed_at')->nullable()->after('delivery_attempts');
            $table->timestamp('cancelled_at')->nullable()->after('confirmed_at');
            $table->timestamp('delivered_at')->nullable()->after('cancelled_at');
            $table->string('cancelled_reason', 200)->nullable()->after('delivered_at');

            // Bloqueo optimista: se incrementa con cada transición de estado.
            $table->unsignedInteger('version')->default(1)->after('cancelled_reason');
        });

        Schema::create('order_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();

            $table->string('from_status', 20)->nullable();
            $table->string('to_status', 20);
            $table->string('reason', 200)->nullable();

            // Quién hizo la transición (Identity/User): indexado, sin FK.
            $table->unsignedBigInteger('changed_by')->nullable()->index();

            $table->timestamps();

            $table->index(['order_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_status_history');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn([
                'chargeable_weight_kg',
                'service_type',
                'payment_responsibility',
                'tariff_id',
                'pricing_snapshot',
                'delivery_type',
                'delivery_location_id',
                'recipient_address',
                'delivery_scheduled_at',
                'requires_signature',
                'delivery_attempts',
                'confirmed_at',
                'cancelled_at',
                'delivered_at',
                'cancelled_reason',
                'version',
            ]);
        });
    }
};
