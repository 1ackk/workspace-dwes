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
        Schema::create('spaces', function (Blueprint $table) {
            $table->id();
            // Única fuente de la pertenencia de un recurso a su responsable.
            $table->foreignId('manager_id')->constrained('users')->restrictOnDelete();
            $table->string('name')->unique();
            $table->text('description')->nullable();
            $table->string('location');
            $table->unsignedSmallInteger('cancellation_notice_hours')->default(24);
            $table->time('opens_at')->default('08:00:00');
            $table->time('closes_at')->default('20:00:00');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('spaces');
    }
};
