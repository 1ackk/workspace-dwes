<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A2: identificador del chat de Telegram del usuario. Nulo = sin avisos por Telegram. Único: un
     * chat no puede recibir los avisos de dos cuentas.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('telegram_chat_id', 32)->nullable()->unique()->after('locale');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['telegram_chat_id']);
            $table->dropColumn('telegram_chat_id');
        });
    }
};
