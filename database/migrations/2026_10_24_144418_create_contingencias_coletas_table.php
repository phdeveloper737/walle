<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('contingencias_coletas', function (Blueprint $table) {
            $table->charset = 'utf8mb4';
            $table->collation = 'utf8mb4_general_ci';
            $table->id();
            $table->foreignId('ciclo_id')->constrained('contingencias_ciclos')->restrictOnDelete();
            $table->integer('fk_c_cliente');
            $table->dateTime('iniciado_em');
            $table->dateTime('terminado_em')->nullable();
            $table->string('status', 30)->default('em_andamento');
            $table->string('erro', 500)->nullable();
            $table->unsignedInteger('total_cliente')->nullable();
            $table->unsignedInteger('total_bot')->nullable();
            $table->unsignedInteger('total_sactus')->nullable();
            $table->unsignedInteger('total_versatus')->nullable();

            $table->unique(['ciclo_id', 'fk_c_cliente']);
            $table->index(['fk_c_cliente', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contingencias_coletas');
    }
};
