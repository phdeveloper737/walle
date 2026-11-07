<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só nos testes: imitação do Help Desk, nunca o Help Desk real (192.168.10.254, somente leitura).
 * Vai para o banco `<DB_DATABASE>_help`.
 * `informacao_tecnica_cleantaxbot`: colunas, NULL e DEFAULT conforme o information_schema do Help Desk.
 * Sem FKs. Confirmado no information_schema do Help Desk: `id` é auto_increment e
 * `data_alteracao` tem `ON UPDATE CURRENT_TIMESTAMP`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('informacao_tecnica_cleantaxbot', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->char('auditar_nfe', 1)->nullable()->default('N');
            $table->char('inserir_300', 1)->nullable()->default('N');
            $table->char('cnpj_excecaodelete', 1)->nullable()->default('N');
            $table->char('emissao_cupons', 1)->nullable()->default('N');
            $table->char('clean_contingencia', 1)->nullable()->default('N');
            $table->char('cod_fiscal', 1)->nullable()->default('N');
            $table->char('tipo_loja', 1)->nullable()->default('T');
            $table->integer('qtd_isentos')->nullable()->default(1);
            $table->timestamp('data_alteracao')->useCurrent()->useCurrentOnUpdate();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informacao_tecnica_cleantaxbot');
    }
};
