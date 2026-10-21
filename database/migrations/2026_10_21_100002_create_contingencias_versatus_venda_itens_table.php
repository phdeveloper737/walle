<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * contingencias_versatus_venda_itens: mesma estrutura do banco suporte (itens das vendas do Versatus em contingência).
 * O nome do índice/chave estrangeira mantém o "contigencias" (sem n) do original.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias_versatus_venda_itens', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_contingencias_versatus_venda')->default(0);
            $table->char('cod_barras', 14)->nullable();
            $table->integer('fk_produto')->nullable();
            $table->string('nome', 60);
            $table->char('unidade', 2)->default('UN');
            $table->decimal('aliquota', 10, 2)->default(0);
            $table->decimal('base_reducao', 10, 2)->default(0);
            $table->decimal('aliquota_reduzida', 10, 2)->default(0);
            $table->char('ncm', 8);
            $table->char('cest', 8)->nullable();
            $table->char('cbenef', 30);
            $table->string('cfop', 4)->nullable();
            $table->char('cst', 3)->default('000');
            $table->char('cst_ibscbs', 3)->nullable();
            $table->char('classtrib_ibs', 6)->nullable();

            $table->index('fk_contingencias_versatus_venda', 'fk_contigencias_versatus_venda');
            $table->foreign('fk_contingencias_versatus_venda', 'fk_contigencias_versatus_venda')
                ->references('id')->on('contingencias_versatus_venda')
                ->cascadeOnDelete()->cascadeOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingencias_versatus_venda_itens');
    }
};
