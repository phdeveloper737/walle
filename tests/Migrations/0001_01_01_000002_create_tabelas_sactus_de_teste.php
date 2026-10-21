<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só nos testes: imitação das tabelas do Sactus (banco actus) que ficam no servidor de cada cliente.
 * Só as colunas que a busca de contingências lê.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias_pdv', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->string('pdv', 10);
            $table->string('cupom', 20);
            $table->dateTime('fiscal_date')->nullable();
            $table->string('cod_motivo', 10)->nullable();
            $table->text('motivo')->nullable();
        });

        Schema::create('sale', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->string('pdv', 10);
            $table->string('cupom', 20);
            $table->dateTime('fiscal_date')->nullable();
            $table->string('chave_acesso_nfce', 44)->nullable();
        });

        Schema::create('sale_itens', function (Blueprint $table) {
            $table->id();
            $table->string('store', 10);
            $table->string('pdv', 10);
            $table->string('cupom', 20);
            $table->string('sku', 14)->nullable();
            $table->string('plu_id', 10)->nullable();
            $table->string('plu_sdesc', 60)->nullable();
            $table->string('unit', 2)->nullable();
            $table->string('aliquota_cheia', 10)->nullable();
            $table->string('reducao_base', 10)->nullable();
            $table->string('taxid', 5)->nullable();
            $table->string('ncm', 8)->nullable();
            $table->string('cest', 8)->nullable();
            $table->string('cbenef', 10)->nullable();
            $table->string('cfop', 4)->nullable();
            $table->string('cst_icms', 3)->nullable();
            $table->string('cst_ibscbs', 3)->nullable();
            $table->string('classtrib_ibs', 6)->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_itens');
        Schema::dropIfExists('sale');
        Schema::dropIfExists('contingencias_pdv');
    }
};
