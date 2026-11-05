<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contingencias_coletas (tabela do walle): detalhe técnico do erro da coleta,
 * visível só para admin (host, porta, exceção e demais dados da tentativa).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('contingencias_coletas', function (Blueprint $table) {
            $table->json('detalhe_tecnico')->nullable()->after('erro');
        });
    }

    public function down(): void
    {
        Schema::table('contingencias_coletas', function (Blueprint $table) {
            $table->dropColumn('detalhe_tecnico');
        });
    }
};
