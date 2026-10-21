<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Tabela nova do walle: um registro por ciclo do robô do Sactus (o antigo só escrevia no log). */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sactus_ciclos', function (Blueprint $table) {
            $table->id();
            $table->dateTime('iniciado_em');
            $table->dateTime('terminado_em')->nullable();
            $table->unsignedInteger('elegiveis')->default(0);
            $table->unsignedInteger('sem_credenciais')->default(0);
            $table->unsignedInteger('offline')->default(0);
            $table->unsignedInteger('coletados')->default(0);
            $table->unsignedInteger('falhas')->default(0);
            $table->unsignedInteger('acl_ok')->default(0);
            $table->unsignedInteger('acl_falha')->default(0);
            $table->unsignedInteger('erp_ok')->default(0);
            $table->unsignedInteger('erp_falha')->default(0);
            $table->unsignedInteger('cfg_ok')->default(0);
            $table->unsignedInteger('cfg_falha')->default(0);
            $table->string('erro', 500)->nullable();

            $table->index('iniciado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sactus_ciclos');
    }
};
