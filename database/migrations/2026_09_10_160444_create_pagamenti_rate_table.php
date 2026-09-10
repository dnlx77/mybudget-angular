<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pagamenti_rate', function (Blueprint $table) {
            $table->id();
            $table->string('nome');
            $table->decimal('importo_totale', 10, 2);
            $table->unsignedInteger('numero_rate');
            $table->date('data_inizio');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pagamenti_rate');
    }
};
