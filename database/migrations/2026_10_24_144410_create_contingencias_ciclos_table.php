<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias_ciclos', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
            $table->id();
            $table->dateTime('iniciado_em');
            $table->dateTime('terminado_em')->nullable();
            $table->string('status', 30)->default('em_andamento');
            $table->unsignedInteger('elegiveis')->default(0);
            $table->unsignedInteger('coletados')->default(0);
            $table->unsignedInteger('falhas')->default(0);
            $table->string('erro', 500)->nullable();

            $table->index('iniciado_em');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingencias_ciclos');
    }
};
