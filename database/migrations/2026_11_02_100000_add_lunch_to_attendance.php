<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Almuerzo en la jornada. Cada agencia define su duración, y el consolidado guarda
 * cuánto duró de verdad: `worked_minutes` deja de contarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agencies', function (Blueprint $table) {
            $table->unsignedSmallInteger('lunch_minutes')->default(60);
        });

        Schema::table('attendance_days', function (Blueprint $table) {
            $table->unsignedInteger('lunch_minutes')->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('attendance_days', function (Blueprint $table) {
            $table->dropColumn('lunch_minutes');
        });

        Schema::table('agencies', function (Blueprint $table) {
            $table->dropColumn('lunch_minutes');
        });
    }
};
