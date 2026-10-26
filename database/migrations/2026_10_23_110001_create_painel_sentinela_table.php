<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * painel_sentinela: mesma estrutura do banco suporte (220). Uma linha por cliente com a última
 * coleta do Bot Sentinela (GET http://<host>/sentinela.php?json=S): servidor, Sactus e VPN.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('painel_sentinela', function (Blueprint $table) {
            $table->collation('utf8mb4_general_ci');

            $table->id();
            $table->integer('fk_c_cliente');
            $table->char('status', 1)->default('N');
            $table->smallInteger('http_code')->default(0);
            $table->string('erro', 500)->default('');
            $table->string('host', 191)->default('');
            $table->string('url', 500)->default('');
            $table->string('hostname', 191)->default('');
            $table->string('os', 191)->default('');
            $table->string('kernel', 191)->default('');
            $table->string('uptime', 60)->default('');
            $table->string('php', 40)->default('');
            $table->decimal('cpu_uso', 5, 1)->nullable();
            $table->smallInteger('cpu_nucleos')->default(0);
            $table->decimal('load1', 6, 2)->default(0);
            $table->decimal('load5', 6, 2)->default(0);
            $table->decimal('load15', 6, 2)->default(0);
            $table->integer('mem_total_mb')->default(0);
            $table->integer('mem_usado_mb')->default(0);
            $table->decimal('mem_pct', 5, 1)->default(0);
            $table->decimal('swap_pct', 5, 1)->default(0);
            $table->string('disco_mount', 120)->default('');
            $table->decimal('disco_total_gb', 10, 1)->default(0);
            $table->decimal('disco_usado_gb', 10, 1)->default(0);
            $table->smallInteger('disco_pct')->default(0);
            $table->char('sactus_rodando', 1)->default('X');
            $table->string('sactus_mtime', 40)->default('');
            $table->char('sactus_md5_ok', 1)->default('X');
            $table->string('sactus_versao', 60)->default('');
            $table->char('vpn_ativo', 1)->default('N');
            $table->string('vpn_tun', 20)->default('');
            $table->integer('vpn_mtu')->default(0);
            $table->char('vpn_inconsistente', 1)->default('N');
            $table->integer('proc_total')->default(0);
            $table->json('json')->nullable();
            $table->integer('duracao_ms')->default(0);
            $table->dateTime('data');

            $table->unique('fk_c_cliente', 'uq_cliente');
            $table->index('status', 'idx_status');
            $table->index('sactus_rodando', 'idx_sactus');
            $table->index('data', 'idx_data');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('painel_sentinela');
    }
};
