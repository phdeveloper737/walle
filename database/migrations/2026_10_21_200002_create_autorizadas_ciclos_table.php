<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tabela nova do walle: um registro por ciclo do robô das Autorizadas.
 * O painel antigo tirava esses números lendo o texto do /var/log/autorizadas.log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('autorizadas_ciclos', function (Blueprint $table) {
            $table->id();
            $table->dateTime('iniciado_em');
            $table->dateTime('terminado_em')->nullable();
            $table->unsignedInteger('removidas')->default(0)->comment('Apagadas por passarem da retenção');
            $table->unsignedInteger('semeadas')->default(0)->comment('Chaves novas vindas do history_contingencia');
            $table->unsignedInteger('na_fila')->default(0);
            $table->unsignedInteger('consultadas')->default(0);
            $table->unsignedInteger('autorizadas')->default(0);
            $table->unsignedInteger('gravadas')->default(0);
            $table->unsignedInteger('falhas')->default(0);
            $table->boolean('consumo_indevido')->default(false)->comment('A Sefaz respondeu 656');
            $table->string('erro', 500)->nullable();

            $table->index('iniciado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('autorizadas_ciclos');
    }
};
