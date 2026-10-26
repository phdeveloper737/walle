<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * informacao_tecnica_painel_contingencias: mesma estrutura do banco suporte. Configuração técnica de acesso
 * ao servidor de cada cliente usada pelo Contingência BOT (uma por cliente; o 220 grava id = fk_c_cliente).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informacao_tecnica_painel_contingencias', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->char('id_loja', 3)->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('razao_social', 100)->nullable();
            $table->string('nome_fantasia', 100)->nullable();
            $table->char('tips_server', 2)->nullable();
            $table->char('tips_pdv', 1)->nullable();
            $table->string('ip_docker_principal', 50)->nullable();
            $table->char('banco_separado_docker', 1)->default('N');
            $table->string('ip_container_docker', 50)->nullable();
            $table->string('porta_http_docker', 50)->nullable()->default('80');
            $table->string('porta_mysql_docker', 50)->nullable()->default('3306');
            $table->string('porta_ssh_docker', 10)->nullable()->default('22');
            $table->string('porta_ssh_container_docker', 10)->nullable();
            $table->string('senha_ssh_docker', 100)->nullable();
            $table->string('banco_mysql_docker', 100)->nullable();
            $table->string('senha_mysql_docker', 100)->nullable();
            $table->string('usuario_mysql_docker', 100)->nullable();
            $table->string('ip_externo_servidor', 50)->nullable();
            $table->string('servidor_mysql', 50)->nullable();
            $table->string('porta_http', 10)->nullable()->default('80');
            $table->string('porta_mysql', 50)->nullable()->default('3306');
            $table->string('porta_ssh', 10)->nullable()->default('22');
            $table->string('banco_mysql', 50)->nullable();
            $table->string('senha_mysql', 100)->nullable();
            $table->string('senha_ssh', 100)->nullable();
            $table->char('ativo_painel_contingencias', 1)->default('S');
            $table->char('deletado', 1)->default('N');
            $table->timestamp('ultima_atualizacao')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->string('ip_publico', 50)->nullable();
            $table->char('emissao_cupons', 1)->nullable()->default('N');

            $table->unique('fk_c_cliente', 'fk_c_cliente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informacao_tecnica_painel_contingencias');
    }
};
