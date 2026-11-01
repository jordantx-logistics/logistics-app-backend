<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * `break-start` tiene 11 caracteres y la columna admitía 10: MySQL rechazaba la marcación
 * con un 500. SQLite no aplica el límite, así que la suite no lo veía.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('type', 20)->change();
        });
    }

    public function down(): void
    {
        Schema::table('attendance_records', function (Blueprint $table) {
            $table->string('type', 10)->change();
        });
    }
};
