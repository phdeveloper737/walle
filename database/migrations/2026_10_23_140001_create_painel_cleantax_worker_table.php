<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_cleantax_worker: mesma estrutura do banco suporte. Uma linha por cliente (sem coluna id,
 * o fk_c_cliente é único) com a última chamada do CleanTax BOT ao cleantaxbot.php do cliente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_cleantax_worker', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            // Desvio do 220 (lá aceita NULL): nenhuma linha real tem NULL e o gravador recusa cliente sem id.
            $table->integer('fk_c_cliente');
            $table->string('empresa', 255)->nullable();
            $table->string('cnpj', 30)->nullable();
            $table->string('servidor', 120)->nullable();
            $table->string('porta_http', 20)->nullable();
            $table->string('banco_dados', 120)->nullable();
            $table->decimal('tempo_execucao_segundos', 10, 3)->nullable();
            $table->integer('http_code')->nullable();
            $table->string('status_execucao', 30)->nullable();
            $table->integer('curl_errno')->nullable();
            $table->string('mensagem', 500)->nullable();
            $table->text('url')->nullable();
            $table->text('response_preview')->nullable();
            $table->dateTime('data_inicio')->nullable();
            $table->dateTime('data_fim')->nullable();
            $table->dateTime('data')->useCurrent()->useCurrentOnUpdate();

            $table->unique('fk_c_cliente', 'fk_c_cliente');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_cleantax_worker');
    }
};
