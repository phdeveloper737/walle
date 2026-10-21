<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só nos testes: imitação mínima das tabelas do Help Desk que a tela de Contingências lê.
 * Tipos copiados do 192.168.10.254 (só as colunas usadas).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c_cliente', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_company')->default(1);
            $table->string('razao_social', 60)->nullable();
            $table->string('nome_fantasia', 60)->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->char('deletado', 1)->default('N');
            $table->char('consultoria', 1)->nullable()->default('N');
            $table->timestamp('ultima_atualizacao')->nullable();
        });

        Schema::create('informacao_tecnica_dataserver', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->char('loja', 3)->nullable();
            $table->string('ip_externo_servidor', 50)->nullable();
            $table->char('servidor_multiempresa', 1)->nullable()->default('N');
            $table->string('pasta_multiempresa', 100)->nullable();
            $table->string('porta_http', 10)->nullable()->default('80');
            $table->string('local_servidor', 20)->nullable();
        });

        Schema::create('informacao_tecnica_pdv_linux', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->default(0);
            $table->integer('contingencias')->nullable();
        });

        Schema::create('informacao_atua_versatus', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->string('contigencia', 11)->nullable()->default('0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informacao_atua_versatus');
        Schema::dropIfExists('informacao_tecnica_pdv_linux');
        Schema::dropIfExists('informacao_tecnica_dataserver');
        Schema::dropIfExists('c_cliente');
    }
};
