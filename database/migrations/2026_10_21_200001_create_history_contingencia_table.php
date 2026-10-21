<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * history_contingencia: mesma estrutura do banco suporte (histórico das contingências por chave).
 * Alimentada pelo robô Contingency; é de onde o robô das Autorizadas tira as chaves para consultar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('history_contingencia', function (Blueprint $table) {
            $table->integer('fk_c_cliente')->default(0);
            $table->char('serie', 50)->nullable();
            $table->char('numero', 9)->nullable();
            $table->string('chave', 44)->nullable()->unique('chave');
            $table->dateTime('data_fiscal_xml')->nullable();
            $table->string('motivo', 256)->nullable();
            $table->timestamp('data_clique')->useCurrent()->useCurrentOnUpdate();
            $table->char('software', 50)->nullable();
            $table->dateTime('data_consulta_sefaz_bot')->nullable();

            $table->index('data_consulta_sefaz_bot', 'data_consulta_sefaz_bot');
            $table->index('data_fiscal_xml', 'idx_data_fiscal');
            $table->index(['fk_c_cliente', 'data_fiscal_xml'], 'idx_cliente_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('history_contingencia');
    }
};
