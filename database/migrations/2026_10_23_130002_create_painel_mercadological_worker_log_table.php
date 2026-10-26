<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_mercadological_worker_log: mesma estrutura do banco suporte. Histórico de cada etapa da
 * classificação mercadológica por cliente (sem chave estrangeira, como no 220).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_mercadological_worker_log', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->integer('id', true);
            $table->integer('fk_c_cliente');
            $table->string('tipo', 20)->comment('ERROR | INFO | SUCCESS');
            $table->string('etapa', 60)->comment('Ex: CONEXAO_300, TRUNCATE, REPLACE_GRUPO...');
            $table->mediumText('mensagem');
            $table->dateTime('criado_em')->useCurrent();

            $table->index('fk_c_cliente', 'idx_cliente');
            $table->index('tipo', 'idx_tipo');
            $table->index('criado_em', 'idx_criado');
            $table->index(['fk_c_cliente', 'criado_em'], 'ix_cliente_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_mercadological_worker_log');
    }
};
