<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela nova do walle: quem fez o quê. Toda ação que mexe fora do walle (servidor de cliente, Sefaz)
 * ou que mostra um segredo fica registrada aqui, com o usuário do Help Desk e o IP.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('auditoria', function (Blueprint $table) {
            $table->id();
            $table->integer('usuario_id')->nullable();
            $table->string('usuario', 100);
            $table->string('acao', 60);
            $table->integer('fk_c_cliente')->nullable();
            $table->json('detalhes')->nullable();
            $table->string('ip', 45)->nullable();
            $table->dateTime('criado_em');

            $table->index('criado_em');
            $table->index(['fk_c_cliente', 'criado_em']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('auditoria');
    }
};
