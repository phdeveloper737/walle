<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela nova do walle: ações manuais no servidor de um cliente (yum -y update, instalar o pacote acl),
 * pedidas por um admin no painel. No sistema antigo o yum rodava sozinho dentro da rotina de ACL.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sactus_acoes', function (Blueprint $table) {
            $table->id();
            $table->integer('fk_c_cliente');
            $table->string('acao', 30);
            $table->enum('status', ['PENDENTE', 'PROCESSANDO', 'CONCLUIDO', 'ERRO'])->default('PENDENTE');
            $table->integer('rc')->nullable();
            $table->mediumText('saida')->nullable();
            $table->string('erro', 1000)->nullable();
            $table->string('alvo', 150)->nullable();
            $table->integer('solicitante_id');
            $table->string('solicitante', 100);
            $table->string('ip', 45)->nullable();
            $table->dateTime('criado_em');
            $table->dateTime('iniciado_em')->nullable();
            $table->dateTime('processado_em')->nullable();

            $table->index(['fk_c_cliente', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sactus_acoes');
    }
};
