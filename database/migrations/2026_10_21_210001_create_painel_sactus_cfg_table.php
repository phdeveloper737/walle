<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_sactus_cfg: mesma estrutura do banco suporte. Uma linha por cliente com a última coleta
 * do /etc/sactus.cfg (via SSH) e o resultado das rotinas de ACL e de atualização do ERP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_sactus_cfg', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente');
            $table->string('metodo', 10)->nullable()->comment('DOCKER | NORMAL | VPN');
            $table->string('host', 100)->nullable();
            $table->integer('porta')->nullable();
            $table->string('usuario', 50)->nullable();
            $table->char('status', 1)->default('N')->comment('S = coletado | N = falhou');
            $table->string('erro', 500)->nullable();
            $table->longText('conteudo')->nullable()->comment('conteudo bruto do /etc/sactus.cfg');
            $table->longText('json')->nullable()->comment('conteudo normalizado em JSON');
            $table->char('acl_status', 1)->default('X');
            $table->string('acl_acao', 60)->default('');
            $table->string('acl_erro', 1000)->default('');
            $table->text('acl_json')->nullable();
            $table->dateTime('acl_data')->nullable();
            $table->char('erp_status', 1)->default('X');
            $table->string('erp_acao', 60)->default('');
            $table->string('erp_erro', 1000)->default('');
            $table->text('erp_json')->nullable();
            $table->dateTime('erp_data')->nullable();
            $table->integer('tentativas')->default(0);
            $table->integer('duracao_ms')->default(0);
            $table->dateTime('data')->nullable();

            $table->unique('fk_c_cliente', 'uk_sactus_cfg_cliente');
            $table->index(['status', 'data'], 'idx_sactus_cfg_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_sactus_cfg');
    }
};
