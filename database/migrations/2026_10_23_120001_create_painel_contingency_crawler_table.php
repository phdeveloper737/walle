<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_contingency_crawler: mesma estrutura do banco suporte. Uma linha por cliente com o total de
 * contingências da última coleta do Contingência BOT (Sactus, Versatus e a soma). Não tem id: a chave é fk_c_cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_contingency_crawler', function (Blueprint $table) {
            $table->integer('fk_c_cliente')->nullable();
            $table->integer('total_cliente')->nullable();
            $table->integer('total_bot')->nullable();
            $table->integer('total_sactus')->nullable();
            $table->integer('total_versatus')->nullable();
            $table->dateTime('data')->useCurrent()->useCurrentOnUpdate();

            $table->unique('fk_c_cliente', 'fk_c_cliente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_contingency_crawler');
    }
};
