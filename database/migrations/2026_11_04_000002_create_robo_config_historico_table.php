<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * robo_config_historico: histórico imutável de alterações da robo_config.
 * Grava ator, IP, valores anterior/novo sanitizados e ação (ativar/desativar/alterar/criar).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('robo_config_historico', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->bigIncrements('id');
            $table->string('robo', 30);
            $table->string('parametro', 60);
            $table->string('acao', 10);
            $table->text('valor_anterior_sanitizado')->nullable();
            $table->text('valor_novo_sanitizado')->nullable();
            $table->string('ator', 100);
            $table->string('ip', 45)->nullable();
            $table->timestamp('criado_em')->useCurrent();

            $table->index(['robo', 'parametro', 'criado_em'], 'idx_robo_parametro_criado_em');
            $table->index(['ator', 'criado_em'], 'idx_ator_criado_em');
            $table->index('criado_em', 'idx_robo_config_historico_criado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('robo_config_historico');
    }
};
