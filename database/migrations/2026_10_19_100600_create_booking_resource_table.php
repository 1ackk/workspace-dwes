<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Pivote con datos propios. Las franjas son semiabiertas [start_at, end_at);
     * que end_at > start_at y que no haya solapes lo garantiza el servicio de
     * reservas bajo bloqueo de los recursos, no el esquema.
     */
    public function up(): void
    {
        Schema::create('booking_resource', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->foreignId('resource_id')->constrained()->restrictOnDelete();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->decimal('precio_final', 8, 2);
            $table->timestamps();

            // Consulta de solapes: por recurso y rango de fechas.
            $table->index(['resource_id', 'start_at', 'end_at']);
            $table->index('booking_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('booking_resource');
    }
};
