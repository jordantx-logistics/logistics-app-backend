<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Caja, movimientos, cierres con descuadre y proyección de cobro.
     *
     * `agency_id`, `cashier_id` y las referencias de `billing_orders` apuntan a
     * otros contextos: columnas indexadas, sin FK (ADR-0006). La única FK es la
     * de `cash_movements`/`cash_closures` a su sesión, que es intra-contexto.
     */
    public function up(): void
    {
        Schema::create('cash_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('cashier_id')->index();
            $table->string('status', 20)->default('open');
            $table->decimal('opening_amount', 12, 2);
            $table->decimal('expected_amount', 12, 2)->nullable();
            $table->decimal('closing_amount', 12, 2)->nullable();
            $table->decimal('difference', 12, 2)->nullable();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['cashier_id', 'status']);
            $table->index(['agency_id', 'status', 'opened_at']);
        });

        Schema::create('cash_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->string('type', 20);
            $table->decimal('amount', 12, 2);
            $table->string('reference_type', 30)->nullable();
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamps();

            $table->index(['cash_session_id', 'type']);
            $table->index(['reference_type', 'reference_id']);
            $table->index('created_at');
        });

        Schema::create('cash_closures', function (Blueprint $table) {
            $table->id();
            $table->foreignId('cash_session_id')->constrained('cash_sessions')->cascadeOnDelete();
            $table->string('status', 20)->default('pending');
            $table->decimal('expected_amount', 12, 2);
            $table->decimal('closing_amount', 12, 2);
            $table->decimal('difference', 12, 2);
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->string('report_path', 255)->nullable();
            $table->timestamps();

            $table->index(['status', 'requested_at']);
            $table->index('cash_session_id');
        });

        Schema::create('billing_orders', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->string('ticket_number', 20)->index();
            $table->char('ticket_code', 8)->index();
            $table->string('status', 20)->default('pending');
            $table->unsignedBigInteger('origin_agency_id')->index();
            $table->unsignedBigInteger('destination_agency_id')->index();
            $table->unsignedBigInteger('sender_id');
            $table->unsignedBigInteger('receiver_id');
            $table->unsignedBigInteger('payer_id')->nullable();
            $table->string('payer_name', 200)->nullable();
            $table->string('customer_document_type', 10)->nullable();
            $table->string('customer_document_number', 20)->nullable();
            $table->decimal('amount_due', 12, 2)->default(0);
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('payment_status', 20)->default('unpaid');
            $table->char('currency', 3)->default('PEN');
            $table->string('payment_responsibility', 10)->default('sender');
            $table->timestamp('delivered_at')->nullable();
            $table->boolean('ready_for_settlement')->default(false);
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['payment_status', 'updated_at']);
            $table->index(['ready_for_settlement', 'delivered_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_orders');
        Schema::dropIfExists('cash_closures');
        Schema::dropIfExists('cash_movements');
        Schema::dropIfExists('cash_sessions');
    }
};
