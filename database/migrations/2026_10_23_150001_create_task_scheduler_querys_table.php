<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_scheduler_querys: mesma estrutura do banco suporte (queries agendadas do Task Scheduler,
 * menu Programação → Ferramentas), que o robô roda no banco de cada cliente.
 * Diferença do walle: fk_a_user fica só com o índice, sem a chave estrangeira para o a_user,
 * porque o a_user está no Help Desk (192.168.10.254), fora do banco do walle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_scheduler_querys', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_a_user')->nullable();
            $table->string('descricao', 373)->default('N');
            $table->dateTime('data_registro')->nullable();
            $table->dateTime('data_finalizado')->nullable();
            $table->string('query_execute', 3737)->nullable();
            $table->char('finalizado', 1)->nullable()->default('N');
            $table->char('ativo', 1)->default('N');
            $table->char('deletado', 1)->default('N');
            $table->integer('fk_a_user_delete')->nullable();

            $table->index('fk_a_user', 'task_scheduler_querys_a_user_fk1');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_scheduler_querys');
    }
};
