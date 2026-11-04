<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * robo_config: configuração persistida dos robôs do walle (tabela nova, sem equivalente no 220).
 * Guarda parâmetros por robô (autorizadas, contingencias, sentinela, cleantax, tarefas, sactus).
 * valor_sanitizado armazena o valor sem segredos (senha, token, certificado, pfx, chave, secret, password → ***).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robo_config', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->bigIncrements('id');
            $table->string('robo', 30);
            $table->string('parametro', 60);
            $table->text('valor')->nullable();
            $table->text('valor_sanitizado')->nullable();
            $table->timestamp('criado_em')->useCurrent();
            $table->timestamp('atualizado_em')->useCurrent()->useCurrentOnUpdate();

            $table->unique(['robo', 'parametro'], 'uq_robo_parametro');
            $table->index('criado_em', 'idx_robo_config_criado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robo_config');
    }
};
