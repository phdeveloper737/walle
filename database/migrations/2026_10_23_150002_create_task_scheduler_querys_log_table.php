<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Expression;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * task_scheduler_querys_log: mesma estrutura do banco suporte (resultado de cada query agendada
 * em cada cliente). Sem chave primária, como no original. fk_c_cliente é o id do cliente
 * (c_cliente do Help Desk), só com índice.
 * A chave estrangeira para task_scheduler_querys não tem ON DELETE (NO ACTION), como no 220:
 * o banco recusa apagar uma query que já tem log.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_scheduler_querys_log', function (Blueprint $table) {
            $table->integer('fk_task_scheduler_querys')->nullable();
            $table->integer('fk_c_cliente')->nullable();
            $table->string('log_query', 3737)->nullable();
            $table->timestamp('data_time')->default(new Expression('(now())'))->useCurrentOnUpdate();

            $table->index('fk_task_scheduler_querys', 'fk_task_scheduler_querys_fk1');
            $table->index('fk_c_cliente', 'fk_c_cliente');
            $table->foreign('fk_task_scheduler_querys', 'fk_task_scheduler_querys_fk1')
                ->references('id')->on('task_scheduler_querys');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_scheduler_querys_log');
    }
};
