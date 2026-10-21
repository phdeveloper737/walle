<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * history_contingencia_auth: mesma estrutura do banco suporte (chaves de NFC-e que o robô consultou na Sefaz).
 * Acréscimo do walle: índice (cliente, autorizada) para separar as contingências já autorizadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('history_contingencia_auth', function (Blueprint $table) {
            $table->integer('fk_c_cliente')->default(0);
            $table->string('chave', 44)->primary();
            $table->char('autorizada', 1)->default('N');
            $table->date('referencia')->nullable();
            $table->dateTime('data_consulta_sefaz_bot')->nullable();

            $table->index(['autorizada', 'data_consulta_sefaz_bot'], 'idx_fila');
            $table->index('referencia', 'idx_referencia');
            $table->index(['fk_c_cliente', 'autorizada'], 'idx_cliente_autorizada');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_contingencia_auth');
    }
};
