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
        Schema::create('tariffs', function (Blueprint $table) {
            $table->id();
            // Sin recurso = regla general del coworking.
            $table->foreignId('resource_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('kind', 20);
            $table->decimal('price_per_hour', 8, 2)->nullable();
            $table->decimal('surcharge_percent', 5, 2)->nullable();
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->date('valid_from')->nullable();
            $table->date('valid_until')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['resource_id', 'active']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tariffs');
    }
};
