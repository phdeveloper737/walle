<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Só nos testes: imitação do Help Desk, nunca o Help Desk real (192.168.10.254, somente leitura).
 * Vai para o banco `<DB_DATABASE>_help` (task 13.3).
 * Estrutura copiada do 220 de testes (`suporte`, MySQL 8.0.42), a confirmar no .254 (pendência P1).
 * `c_cliente` e `informacao_tecnica_dataserver` têm todas as colunas, inclusive as de segredo,
 * porque os consumidores ao vivo leem `i.*` e os testes gravam `senha_ssh`.
 * Colunas NOT NULL sem default na origem ganham default de teste (0 ou ''), para os testes
 * inserirem só `razao_social` e `deletado`. Sem FKs: company e c_cidade não existem no teste.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('c_cliente', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->integer('id', true);
            $table->integer('fk_company')->default(1);
            $table->integer('fk_c_cidade')->default(0);
            $table->char('tipo', 1)->default('J');
            $table->char('loja', 5)->default('');
            $table->string('razao_social', 60)->nullable();
            $table->string('nome_fantasia', 60)->nullable();
            $table->string('contato', 100)->nullable();
            $table->string('cnpj', 18)->nullable();
            $table->string('ie', 20)->default('ISENTO');
            $table->string('cpf', 15)->nullable();
            $table->string('rg', 20)->nullable();
            $table->string('tipo_logradouro', 60)->default('');
            $table->string('logradouro', 60)->default('');
            $table->string('bairro', 60)->default('');
            $table->string('complemento', 255)->nullable();
            $table->string('numero', 50)->default('');
            $table->char('cep', 10)->nullable();
            $table->char('telefone', 16)->nullable();
            $table->char('telefone2', 16)->nullable();
            $table->char('telefone3', 16)->nullable();
            $table->char('telefone4', 16)->nullable();
            $table->char('celular', 16)->nullable();
            $table->string('email', 200)->nullable();
            $table->char('deletado', 1)->default('N');
            $table->string('weblink', 200)->default('http://');
            $table->longText('tef')->nullable();
            $table->longText('outros')->nullable();
            $table->string('senha_touch', 15)->nullable();
            $table->char('atualizar_erp', 1)->nullable()->default('N');
            $table->char('status_update_erp', 1)->nullable()->default('F');
            $table->mediumText('msg_update_erp')->nullable();
            $table->char('software_update_erp', 255)->nullable();
            $table->integer('fk_a_user_update_erp')->default(0);
            $table->char('consultoria', 1)->nullable()->default('N');
            $table->char('monitoramento', 1)->nullable()->default('N');
            $table->char('bloqueio_financeiro', 1)->nullable()->default('N');
            $table->char('bloqueio_avaliacao', 1)->nullable()->default('N');
            $table->char('bloqueio_jm_scan', 1)->nullable()->default('N');
            $table->dateTime('data_cadastro')->nullable();
            $table->timestamp('ultima_atualizacao')->nullable();
            $table->char('testes', 1)->nullable()->default('N');
            $table->string('dias_prioridade', 5)->nullable();
            $table->date('data_inicio_prioridade')->nullable();
            $table->date('data_implantacao')->nullable();
            $table->char('atendimento_aberto', 1)->nullable()->default('N');
            $table->char('show_report', 1)->nullable()->default('S');
            $table->string('ultimo_nsu', 50)->nullable()->default('0');
            $table->timestamp('timestamp_ultimo_nsu')->nullable()->useCurrent();
            $table->integer('fk_c_r_fiscal')->nullable();
            $table->integer('fk_c_contadores')->nullable();
            $table->integer('fk_c_contadores_contato')->nullable();
            $table->string('cnpj_antigo', 18)->nullable();
            $table->char('enviar_rota', 1)->nullable()->default('S');
            $table->char('regime', 1)->nullable();
            $table->string('localizacao', 500)->nullable();
            $table->char('excecao_backup', 1)->nullable()->default('N');
            $table->char('executar_bot_class_mercadologica', 1)->nullable()->default('N');
            $table->dateTime('data_exec_bot_class_mercadologica')->nullable();
            $table->char('ctributaria', 1)->nullable()->default('N');

            $table->index('fk_company', 'fk_c_cliente1');
            $table->index('fk_c_cidade', 'fk_c_cliente2');
        });

        Schema::create('informacao_tecnica_dataserver', function (Blueprint $table) {
            $table->charset('utf8mb4');
            $table->collation('utf8mb4_general_ci');

            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->char('loja', 3)->nullable();
            $table->string('servidor_mysql', 50)->nullable();
            $table->string('banco_mysql', 50)->nullable();
            $table->string('porta_mysql', 50)->nullable()->default('3306');
            $table->string('usuario_mysql', 50)->nullable();
            $table->string('senha_mysql', 50)->nullable();
            $table->string('ip_externo_servidor', 50)->nullable();
            $table->string('ip_publico', 50)->nullable();
            $table->char('servidor_multiempresa', 1)->nullable()->default('N');
            $table->string('pasta_multiempresa', 100)->nullable();
            $table->string('key_rsa_amazon', 100)->nullable();
            $table->string('porta_http', 10)->nullable()->default('80');
            $table->string('porta_ssh', 10)->nullable()->default('22');
            $table->string('ip_interno_servidor', 50)->nullable();
            $table->string('url_acesso', 200)->nullable();
            $table->string('url_debug_venda', 500)->nullable();
            $table->string('pin_acesso', 50)->nullable();
            $table->string('senha_acesso', 50)->nullable();
            $table->string('local_servidor', 20)->nullable();
            $table->string('mac_servidor', 50)->nullable();
            $table->string('usuario_ssh', 50)->nullable()->default('');
            $table->string('senha_ssh', 100)->nullable()->default('');
            $table->string('servidor_proxmox', 100)->nullable();
            $table->string('senha_proxmox', 100)->nullable();
            $table->string('integradora_tef', 100)->nullable();
            $table->string('servidor_tef', 100)->nullable();
            $table->string('ambiente_tef', 100)->nullable();
            $table->string('numero_logico1', 100)->nullable();
            $table->string('numero_logico2', 100)->nullable();
            $table->string('porta_api', 10)->nullable();
            $table->string('token_api', 500)->nullable();
            $table->string('servidor_docker', 50)->nullable()->default('');
            $table->string('ip_container_docker', 50)->nullable();
            $table->string('porta_http_docker', 50)->nullable();
            $table->string('porta_ssh_docker', 10)->nullable()->default('');
            $table->string('usuario_ssh_docker', 50)->nullable()->default('');
            $table->string('senha_ssh_docker', 100)->nullable()->default('');
            $table->string('porta_mysql_docker', 10)->nullable();
            $table->string('banco_mysql_docker', 50)->nullable();
            $table->string('usuario_mysql_docker', 50)->nullable();
            $table->string('senha_mysql_docker', 50)->nullable();
            $table->string('range_docker_de', 10)->nullable();
            $table->string('range_docker_ate', 10)->nullable();
            $table->string('range_pdv_de', 10)->nullable();
            $table->string('range_pdv_ate', 10)->nullable();
            $table->integer('fk_a_user_ultima_alteracao')->nullable();
            $table->timestamp('ultima_atualizacao')->nullable()->useCurrent()->useCurrentOnUpdate();
            $table->dateTime('data_atualizacao_senha_database')->nullable();
            $table->dateTime('data_atualizacao_erp')->nullable();
            $table->dateTime('data_atualizacao_sactus')->nullable();
            $table->string('unify_database', 7373)->nullable();
            $table->char('sactus_licence_lojas', 50)->nullable();
            $table->char('sactus_licence_pdvs', 50)->nullable();
            $table->char('sactus_licence_vencimento', 50)->nullable();
            $table->string('versao_servidor', 255)->nullable();
            $table->string('vpn_senha', 1000)->nullable();
            $table->mediumText('vpn_begin')->nullable();
            $table->mediumText('vpn_private')->nullable();
            $table->date('vpn_data_renovado')->nullable();
            $table->date('vpn_prox_vencimento')->nullable();
            $table->longText('vpn_ovpn')->nullable();
            $table->string('versao_mysql', 50)->nullable()->default('5.1.73');
            $table->longText('obs_mysql')->nullable();
            $table->decimal('tamanho_banco_erp', 18, 2)->nullable()->default(0);
            $table->decimal('tamanho_banco_actus', 18, 2)->nullable()->default(0);
            $table->string('senha_mysql_antiga', 50)->nullable()->default('SEM ALTERCAO');
            $table->string('senha_ssh_antiga', 50)->nullable()->default('SEM ALTERCAO');
            $table->timestamp('data_ult_senha_mysql')->nullable()->default('2026-01-01 00:00:00');
            $table->timestamp('data_ult_senha_ssh')->nullable()->default('2026-01-01 00:00:00');
            $table->char('service_acl', 1)->default('N');
            $table->char('atualizar_erp', 1)->default('N');
            $table->char('ajustar_mtu', 1)->default('N');
            $table->timestamp('data_instalacao_auditoria')->nullable();

            $table->index('fk_c_cliente', 'idx_dataserver_cliente');
            $table->index(['local_servidor', 'banco_mysql', 'loja', 'fk_c_cliente'], 'idx_dataserver_open');
            $table->index(['local_servidor', 'ip_externo_servidor', 'porta_mysql', 'banco_mysql', 'loja', 'fk_c_cliente'], 'idx_dataserver_local');
        });

        Schema::create('informacao_tecnica_pdv_linux', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->default(0);
            $table->integer('contingencias')->nullable();
        });

        Schema::create('informacao_atua_versatus', function (Blueprint $table) {
            $table->integer('id', true);
            $table->integer('fk_c_cliente')->nullable();
            $table->string('contigencia', 11)->nullable()->default('0');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('informacao_atua_versatus');
        Schema::dropIfExists('informacao_tecnica_pdv_linux');
        Schema::dropIfExists('informacao_tecnica_dataserver');
        Schema::dropIfExists('c_cliente');
    }
};
