<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * a_user_acessos: mesma estrutura do banco suporte (entrada e saída de cada login).
 * Acréscimo do walle: índice em fk_a_user.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a_user_acessos', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_a_user')->default(0);
            $table->timestamp('data_hora_login')->nullable();
            $table->integer('qtd_atendimento_login')->default(0);
            $table->timestamp('data_hora_logout')->nullable();
            $table->integer('qtd_atendimento_logout')->default(0);

            $table->index('fk_a_user');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a_user_acessos');
    }
};
