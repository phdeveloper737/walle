<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só nos testes: imitação mínima do a_user do Help Desk.
 * No walle de verdade essa tabela fica no 192.168.10.254 e é somente leitura.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('a_user', function (Blueprint $table) {
            $table->integer('id', true);
            $table->string('name', 45);
            $table->string('user', 10);
            $table->string('tx_senha', 100);
            $table->char('deletado', 1)->default('N');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('a_user');
    }
};
