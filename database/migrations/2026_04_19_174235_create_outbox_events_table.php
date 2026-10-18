<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('outbox_events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type');
            $table->json('payload');
            $table->string('status', 20)->default('pending'); // pending, processing, processed, failed
            $table->integer('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('event_type');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('outbox_events');
    }
};
