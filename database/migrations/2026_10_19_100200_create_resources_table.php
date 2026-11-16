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
        Schema::create('resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('space_id')->constrained()->restrictOnDelete();
            $table->string('type', 20);
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedSmallInteger('capacity')->default(1);
            $table->decimal('hourly_rate', 8, 2);
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['space_id', 'name']);
            $table->index(['space_id', 'active']);
            $table->index(['type', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('resources');
    }
};
