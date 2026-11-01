<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Proyección de Core alimentada por los eventos de pago. Permite que la
     * cancelación de una orden consulte el dinero cobrado sin hacer JOIN contra
     * `payments` (ADR-0006).
     */
    public function up(): void
    {
        Schema::create('order_payment_projections', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->decimal('paid_amount', 12, 2)->default(0);
            $table->decimal('refunded_amount', 12, 2)->default(0);
            $table->string('status', 20)->default('unpaid');
            $table->timestamp('last_event_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_payment_projections');
    }
};
