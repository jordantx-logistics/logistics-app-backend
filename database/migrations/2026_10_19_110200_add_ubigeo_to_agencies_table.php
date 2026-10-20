<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Ubigeo del distrito de la agencia.
     *
     * Es el punto de entrada de la resolución de tarifas: agencia → ubigeo →
     * `location` → zona. Se deja **nullable** porque las agencias del MVP no lo
     * tienen y no hay padrón para deducirlo; sin ubigeo, la cotización cae al
     * tarifario `national` (o al motor `legacy` si `PRICING_ENGINE=legacy`).
     */
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->char('ubigeo', 6)->nullable()->after('district')->index();
        });
    }

    public function down(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('ubigeo');
        });
    }
};
