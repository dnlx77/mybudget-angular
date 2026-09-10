<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('operazioni', function (Blueprint $table) {
            $table->foreignId('pagamento_rata_id')->nullable()->after('conto_id')->constrained('pagamenti_rate')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('operazioni', function (Blueprint $table) {
            $table->dropForeign(['pagamento_rata_id']);
            $table->dropColumn('pagamento_rata_id');
        });
    }
};
