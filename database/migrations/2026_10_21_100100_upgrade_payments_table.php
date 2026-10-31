<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Lleva `payments` del MVP al modelo de Finanzas (Fase 5).
     *
     * Es aditiva en datos: se copia `processed_by` a `created_by` antes de
     * retirar la columna vieja. `cash_session_id` es la única FK que se añade,
     * y es intra-contexto (cash_sessions); `agency_id` se denormaliza para
     * listar y acotar por agencia sin pasar por la sesión.
     */
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->unsignedBigInteger('cash_session_id')->nullable()->index()->after('order_id');
            $table->unsignedBigInteger('agency_id')->nullable()->index()->after('cash_session_id');
            $table->char('currency', 3)->default('PEN')->after('amount');
            $table->string('document_type', 10)->nullable()->after('payment_method');
            $table->unsignedBigInteger('created_by')->nullable()->index()->after('status');
            $table->timestamp('paid_at')->nullable()->after('created_by');
        });

        DB::table('payments')->update([
            'created_by' => DB::raw('processed_by'),
            'paid_at' => DB::raw('created_at'),
        ]);

        // SQLite no elimina una columna indexada sin borrar antes el índice.
        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex(['processed_by']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('processed_by');
            $table->index(['order_id', 'status']);
            $table->foreign('cash_session_id')->references('id')->on('cash_sessions')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropForeign(['cash_session_id']);
            $table->dropIndex(['order_id', 'status']);
            $table->unsignedBigInteger('processed_by')->nullable()->index()->after('status');
        });

        DB::table('payments')->update(['processed_by' => DB::raw('created_by')]);

        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn(['cash_session_id', 'agency_id', 'currency', 'document_type', 'created_by', 'paid_at']);
        });
    }
};
