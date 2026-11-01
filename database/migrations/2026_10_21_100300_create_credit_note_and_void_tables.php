<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Notas de crédito, anulaciones y bitácora de transmisiones SUNAT.
     */
    public function up(): void
    {
        Schema::create('credit_notes', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 20)->unique();
            $table->char('ticket_code', 8)->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->foreignId('payment_id')->constrained('payments');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->unsignedBigInteger('agency_id')->index();
            $table->unsignedBigInteger('cash_session_id')->nullable()->index();
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('reason', 2);
            $table->string('note', 255)->nullable();
            $table->string('status', 20)->default('issued');
            $table->unsignedBigInteger('created_by')->nullable()->index();
            $table->timestamp('issued_at');
            $table->string('xml_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('cdr_path', 255)->nullable();
            $table->string('sunat_code', 20)->nullable();
            $table->string('sunat_message', 255)->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index(['status', 'issued_at']);
        });

        Schema::create('voids', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->foreignId('payment_id')->nullable()->constrained('payments');
            $table->foreignId('invoice_id')->nullable()->constrained('invoices');
            $table->string('reason', 255);
            $table->string('status', 20)->default('requested');
            $table->unsignedBigInteger('requested_by')->index();
            $table->unsignedBigInteger('approved_by')->nullable()->index();
            $table->timestamp('requested_at');
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('rejected_at')->nullable();
            $table->string('rejection_reason', 255)->nullable();
            $table->timestamps();

            $table->index(['payment_id', 'status']);
            $table->index(['status', 'requested_at']);
        });

        Schema::create('sunat_transmissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('invoice_id')->nullable()->constrained('invoices')->cascadeOnDelete();
            $table->foreignId('credit_note_id')->nullable()->constrained('credit_notes')->cascadeOnDelete();
            $table->unsignedInteger('attempt');
            $table->string('endpoint', 120);
            $table->string('request_hash', 64);
            $table->string('response_code', 20)->nullable();
            $table->string('response_message', 255)->nullable();
            $table->string('status', 20);
            $table->timestamp('created_at')->nullable();

            $table->index(['invoice_id', 'attempt']);
            $table->index(['credit_note_id', 'attempt']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sunat_transmissions');
        Schema::dropIfExists('voids');
        Schema::dropIfExists('credit_notes');
    }
};
