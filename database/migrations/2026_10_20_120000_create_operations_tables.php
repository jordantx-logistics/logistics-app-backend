<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Operaciones / Tracking (Fase 4).
     *
     * Cinco subcontextos: Reception, Shipment, Inventory, Tracking y Delivery.
     * Las referencias entre ellos y hacia Core/Identity son columnas indexadas
     * sin FK (ADR-0006); las FK solo existen dentro del mismo subcontexto
     * (p. ej. `reception_items.reception_id`).
     *
     * `tracking_events` se particiona por mes en MySQL (ADR-0003). SQLite —la
     * suite— no soporta particionado: la misma tabla se crea plana con el
     * esquema equivalente.
     */
    public function up(): void
    {
        Schema::create('shipments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->char('ticket_code', 8)->unique();
            $table->string('ticket_number', 20)->index();
            $table->unsignedBigInteger('origin_agency_id')->index();
            $table->unsignedBigInteger('destination_agency_id')->index();
            $table->unsignedBigInteger('current_location_agency_id')->nullable()->index();
            $table->string('status', 24)->default('created')->index();
            $table->dateTime('delivered_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index(['destination_agency_id', 'status']);
            $table->index(['origin_agency_id', 'status']);
        });

        Schema::create('receptions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('received_by')->nullable()->index();
            $table->dateTime('received_at');
            $table->string('status', 20)->default('completed');
            $table->timestamps();

            $table->index(['agency_id', 'received_at']);
        });

        Schema::create('reception_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reception_id')->constrained('receptions')->cascadeOnDelete();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->char('ticket_code', 8);
            $table->uuid('scan_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('embarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('vehicle_id')->index();
            $table->unsignedBigInteger('driver_id')->index();
            $table->string('route_ref', 64)->nullable();
            $table->dateTime('departed_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'departed_at']);
        });

        Schema::create('embark_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('embark_id')->constrained('embarks')->cascadeOnDelete();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->char('ticket_code', 8);
            $table->uuid('scan_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('disembarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('embark_id')->constrained('embarks')->cascadeOnDelete();
            $table->dateTime('arrived_at')->nullable();
            $table->timestamps();
        });

        Schema::create('disembark_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('disembark_id')->constrained('disembarks')->cascadeOnDelete();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->char('ticket_code', 8);
            $table->uuid('scan_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('inventory_counts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('status', 20)->default('open');
            $table->unsignedBigInteger('counted_by')->nullable()->index();
            $table->dateTime('finished_at')->nullable();
            $table->timestamps();

            $table->index(['agency_id', 'status']);
        });

        Schema::create('inventory_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_count_id')->constrained('inventory_counts')->cascadeOnDelete();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->char('ticket_code', 8);
            $table->boolean('expected')->default(true);
            $table->boolean('found')->default(true);
            $table->integer('difference')->default(0);
            $table->string('motive', 255)->nullable();
            $table->timestamps();
        });

        Schema::create('deliveries', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->char('ticket_code', 8)->index();
            $table->string('ticket_number', 20)->index();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('courier_id')->index();
            $table->string('status', 24)->default('assigned')->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->dateTime('delivered_at')->nullable();
            $table->string('failure_reason', 255)->nullable();
            $table->string('recipient_name', 120)->nullable();
            $table->string('recipient_document', 20)->nullable();
            $table->timestamps();

            $table->unique(['shipment_id', 'attempt']);
            $table->index(['courier_id', 'status']);
            $table->index(['agency_id', 'status']);
        });

        Schema::create('delivery_evidence', function (Blueprint $table) {
            $table->id();
            $table->foreignId('delivery_id')->constrained('deliveries')->cascadeOnDelete();
            $table->string('signature_path', 255);
            $table->string('photo_path', 255);
            $table->decimal('latitude', 10, 7);
            $table->decimal('longitude', 10, 7);
            $table->dateTime('captured_at');
            $table->timestamps();
        });

        Schema::create('scans', function (Blueprint $table) {
            $table->id();
            $table->uuid('scan_id')->unique();
            $table->unsignedBigInteger('shipment_id')->index();
            $table->unsignedBigInteger('embark_id')->nullable()->index();
            $table->string('scan_type', 24)->index();
            $table->string('device_id', 64)->nullable();
            $table->unsignedBigInteger('agency_id')->nullable()->index();
            $table->dateTime('scanned_at');
            $table->dateTime('synced_at')->nullable();
            $table->json('payload')->nullable();
            $table->timestamps();

            $table->index(['shipment_id', 'scanned_at']);
        });

        $this->createTrackingEvents();
    }

    public function down(): void
    {
        Schema::dropIfExists('tracking_events');
        Schema::dropIfExists('scans');
        Schema::dropIfExists('delivery_evidence');
        Schema::dropIfExists('deliveries');
        Schema::dropIfExists('inventory_items');
        Schema::dropIfExists('inventory_counts');
        Schema::dropIfExists('disembark_items');
        Schema::dropIfExists('disembarks');
        Schema::dropIfExists('embark_items');
        Schema::dropIfExists('embarks');
        Schema::dropIfExists('reception_items');
        Schema::dropIfExists('receptions');
        Schema::dropIfExists('shipments');
    }

    private function createTrackingEvents(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            Schema::create('tracking_events', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('shipment_id');
                $table->unsignedBigInteger('order_id');
                $table->string('event_type', 40);
                $table->string('status', 24);
                $table->unsignedBigInteger('agency_id')->nullable();
                $table->uuid('scan_id')->nullable();
                $table->json('meta')->nullable();
                $table->dateTime('created_at');

                $table->index(['shipment_id', 'created_at']);
                $table->index(['order_id', 'created_at']);
                $table->index('scan_id');
                $table->index(['event_type', 'created_at']);
            });

            return;
        }

        $partitions = [];
        $month = CarbonImmutable::now()->startOfMonth();

        for ($i = 0; $i < 3; $i++) {
            $next = $month->addMonth();
            $partitions[] = sprintf(
                "PARTITION p%s VALUES LESS THAN (TO_DAYS('%s'))",
                $month->format('Y_m'),
                $next->format('Y-m-d'),
            );
            $month = $next;
        }

        $partitions[] = 'PARTITION pmax VALUES LESS THAN (MAXVALUE)';

        DB::statement(<<<'SQL'
            CREATE TABLE tracking_events (
                id           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                shipment_id  BIGINT UNSIGNED NOT NULL,
                order_id     BIGINT UNSIGNED NOT NULL,
                event_type   VARCHAR(40) NOT NULL,
                status       VARCHAR(24) NOT NULL,
                agency_id    BIGINT UNSIGNED NULL,
                scan_id      CHAR(36) NULL,
                meta         JSON NULL,
                created_at   DATETIME NOT NULL,
                PRIMARY KEY (id, created_at),
                KEY idx_shipment (shipment_id, created_at),
                KEY idx_order (order_id, created_at),
                KEY idx_scan (scan_id),
                KEY idx_type (event_type, created_at)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            PARTITION BY RANGE (TO_DAYS(created_at)) (
            SQL.implode(",\n", $partitions)."\n)");
    }
};
