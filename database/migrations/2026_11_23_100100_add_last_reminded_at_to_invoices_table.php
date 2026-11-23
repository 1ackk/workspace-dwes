<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Último aviso de factura vencida (invoices:send-overdue-reminders). Nulo si nunca se ha
     * avisado; el comando no vuelve a avisar hasta que pasen los días de --every-days.
     */
    public function up(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->timestamp('last_reminded_at')->nullable()->after('due_at');
        });
    }

    public function down(): void
    {
        Schema::table('invoices', function (Blueprint $table) {
            $table->dropColumn('last_reminded_at');
        });
    }
};
