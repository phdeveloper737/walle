<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contingencias_versatus_venda: mesma estrutura do banco suporte (vendas do Versatus em contingência).
 * fk_contingencias_versatus é o id do cliente (c_cliente do Help Desk).
 * Acréscimo do walle: índice (cliente, data) para a lista de contingências do cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias_versatus_venda', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_contingencias_versatus')->nullable();
            $table->bigInteger('id_venda')->nullable()->unique('id_venda');
            $table->integer('fk_company')->nullable();
            $table->integer('pdv')->nullable();
            $table->string('cupom', 50)->nullable();
            $table->timestamp('data_hora')->nullable();
            $table->longText('motivo')->nullable();
            $table->string('codigo', 50)->nullable();
            $table->string('chave', 50)->nullable();

            $table->index(['fk_contingencias_versatus', 'data_hora'], 'idx_cliente_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingencias_versatus_venda');
    }
};
