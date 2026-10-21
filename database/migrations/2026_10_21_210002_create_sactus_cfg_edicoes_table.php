<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * sactus_cfg_edicoes: mesma estrutura do banco suporte. Pedido de edição de uma flag do sactus.cfg:
 * o painel só registra; quem aplica no servidor do cliente é o robô do Sactus.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sactus_cfg_edicoes', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente');
            $table->enum('tipo_edicao', ['ADICIONAR', 'REMOVER']);
            $table->string('flag', 255);
            $table->mediumText('valor')->nullable();
            $table->enum('status', ['PENDENTE', 'PROCESSANDO', 'CONCLUIDO', 'ERRO'])->default('PENDENTE');
            $table->mediumText('mensagem')->nullable();
            $table->string('solicitante', 100)->nullable();
            $table->string('ip', 45)->nullable();
            $table->string('lock_token', 32)->nullable();
            $table->integer('tentativas')->default(0);
            $table->dateTime('criado_em');
            $table->dateTime('iniciado_em')->nullable();
            $table->dateTime('processado_em')->nullable();

            $table->index(['fk_c_cliente', 'status'], 'idx_cliente_status');
            $table->index('status', 'idx_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sactus_cfg_edicoes');
    }
};
