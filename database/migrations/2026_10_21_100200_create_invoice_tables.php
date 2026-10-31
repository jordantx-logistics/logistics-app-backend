<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Series y comprobantes. `invoice_series` guarda los datos fiscales del
     * emisor por agencia y tipo; el correlativo se reserva con bloqueo de fila
     * en el alta del pago.
     */
    public function up(): void
    {
        Schema::create('invoice_series', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('agency_id')->index();
            $table->string('document_type', 10);
            $table->string('series', 4);
            $table->unsignedBigInteger('next_number')->default(1);
            $table->boolean('is_active')->default(true);
            $table->boolean('is_default')->default(false);
            $table->string('issuer_ruc', 11);
            $table->string('issuer_name', 200);
            $table->string('issuer_trade_name', 200)->nullable();
            $table->string('issuer_address', 255)->nullable();
            $table->string('issuer_ubigeo', 6)->nullable();
            $table->string('establishment_code', 4)->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'document_type', 'series']);
            $table->index(['agency_id', 'document_type', 'is_active']);
            $table->index(['agency_id', 'document_type', 'is_default']);
        });

        Schema::create('invoices', function (Blueprint $table) {
            $table->id();
            $table->string('ticket_number', 30)->unique();
            $table->unsignedBigInteger('order_id')->index();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->unsignedBigInteger('agency_id')->index();
            $table->foreignId('invoice_series_id')->constrained('invoice_series');
            $table->string('document_type', 10);
            $table->string('series', 4);
            $table->unsignedBigInteger('number');
            $table->string('customer_document_type', 10)->nullable();
            $table->string('customer_document_number', 20)->nullable();
            $table->string('customer_name', 200)->nullable();
            $table->decimal('taxable_amount', 12, 2);
            $table->decimal('igv', 12, 2);
            $table->decimal('total', 12, 2);
            $table->char('currency', 3)->default('PEN');
            $table->string('status', 20)->default('pending');
            $table->string('xml_path', 255)->nullable();
            $table->string('pdf_path', 255)->nullable();
            $table->string('cdr_path', 255)->nullable();
            $table->string('sunat_code', 20)->nullable();
            $table->string('sunat_message', 255)->nullable();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();

            $table->unique(['agency_id', 'series', 'number']);
            $table->index(['status', 'issued_at']);
            $table->index(['order_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('invoices');
        Schema::dropIfExists('invoice_series');
    }
};
