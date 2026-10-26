<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_mercadological_worker: mesma estrutura do banco suporte. Uma linha por cliente (sem coluna id,
 * o fk_c_cliente é único) com o resumo da última execução da classificação mercadológica.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_mercadological_worker', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->integer('fk_c_cliente')->nullable();
            $table->integer('total')->nullable();
            $table->dateTime('data')->useCurrent()->useCurrentOnUpdate();
            $table->integer('lidos')->default(0)->comment('Produtos lidos no cliente');
            $table->integer('fora_base')->default(0)->comment('Sem correspondencia na base padrao');
            $table->integer('conferidos')->default(0)->comment('Produtos comparados');
            $table->integer('corretos')->default(0)->comment('Ja classificados corretamente');
            $table->integer('divergentes')->default(0)->comment('Divergentes encontrados na leitura');
            $table->integer('atualizados')->default(0)->comment('Corrigidos nesta execucao');
            $table->integer('restantes')->default(0)->comment('Fila para os proximos ciclos');
            $table->smallInteger('lotes')->default(0)->comment('Lotes aplicados nesta execucao');
            $table->integer('classificacao')->default(0)->comment('Escritas em grupo/categoria/familia');
            $table->smallInteger('erros')->default(0)->comment('Erros nao fatais na execucao');
            $table->decimal('segundos', 8, 2)->default(0)->comment('Duracao da execucao');
            $table->char('parcial', 1)->default('N');
            $table->string('status', 12)->default('OK')->comment('OK | PARCIAL | ERRO');
            $table->string('etapa_final', 40)->default('');
            $table->string('ultimo_erro', 255)->default('');
            $table->bigInteger('atualizados_acumulado')->default(0)->comment('Somatorio historico de correcoes');

            $table->unique('fk_c_cliente', 'fk_c_cliente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_mercadological_worker');
    }
};
