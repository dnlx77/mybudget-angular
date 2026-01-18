<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::table('operazioni', function (Blueprint $table) {
            // Aggiungiamo la colonna, ma NULLABLE.
            // I dati vecchi avranno NULL, quelli nuovi avranno il codice.
            $table->uuid('transfer_code')->nullable()->after('trasferimento')->index();
        });
    }

    public function down()
    {
        Schema::table('operazioni', function (Blueprint $table) {
            $table->dropColumn('transfer_code');
        });
    }
};
