<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use App\Models\User;

return new class extends Migration
{
    public function up()
    {
        // 1. Aggiungiamo la colonna come "nullable" (per non far bloccare MySQL)
        Schema::table('conti', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        Schema::table('operazioni', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
        });

        // =========================================================
        // 2. POPOLIAMO I DATI ESISTENTI
        // =========================================================

        // Prendiamo il primo utente nel database (che presumibilmente sei tu)
        $firstUser = User::first();

        if ($firstUser) {
            // Aggiorniamo tutte le righe vecchie assegnandole a te
            DB::table('conti')->whereNull('user_id')->update(['user_id' => $firstUser->id]);
            DB::table('tags')->whereNull('user_id')->update(['user_id' => $firstUser->id]);
            DB::table('operazioni')->whereNull('user_id')->update(['user_id' => $firstUser->id]);
        }

        // =========================================================
        // 3. RENDIAMO LA COLONNA OBBLIGATORIA (Best Practice)
        // =========================================================
        // Ora che non ci sono più righe con user_id = null, possiamo blindare il DB

        Schema::table('conti', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });

        Schema::table('operazioni', function (Blueprint $table) {
            $table->unsignedBigInteger('user_id')->nullable(false)->change();
        });
    }

    public function down()
    {
        Schema::table('conti', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('tags', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });

        Schema::table('operazioni', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};
