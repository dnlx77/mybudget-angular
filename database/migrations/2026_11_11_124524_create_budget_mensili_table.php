<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('budget_mensili', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('anno');
            $table->unsignedTinyInteger('mese');
            $table->decimal('importo', 10, 2);
            $table->timestamps();

            $table->unique(['user_id', 'anno', 'mese']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('budget_mensili');
    }
};
