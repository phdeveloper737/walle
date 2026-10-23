# PLAN.md

## Sprint 1 — O banco `walle` tem todas as tabelas locais do 220 que ainda faltam, com os dados importados, e a escrita controlada no Help Desk existe e começa desligada (`docker exec walle-app ./vendor/bin/pest tests/Migrations tests/Feature/HelpDesk tests/Feature/ImportarSuporteTest.php` passa)

### Fase 1 — Tabelas locais do 220 e escrita controlada no Help Desk · conclui com `docker exec walle-app ./vendor/bin/pest tests/Migrations tests/Feature/HelpDesk`
> Dependências: nenhuma
> Paralelismo: 1.1, 1.2, 1.3, 1.4, 1.5 e 1.6 rodam em paralelo (cada uma cria só os seus arquivos de migration, model e teste)

#### Task 1.1 — Tabela `painel_sentinela` (Bot Sentinela)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE painel_sentinela` do MySQL do contêiner `testes220`
- Output: `database/migrations/<data>_create_painel_sentinela_table.php` com exatamente as mesmas colunas e índices do 220 (collation `utf8mb4_general_ci`) e `app/Models/PainelSentinela.php` (`protected $table = 'painel_sentinela'`, `$timestamps` conforme a tabela, casts de data/JSON)
- Testes críticos:
  - [ ] `tests/Migrations/PainelSentinelaTest.php`: depois de `migrate`, a tabela tem a mesma lista de colunas do 220 (lista fixada no teste) e o model grava e lê uma linha
  - [ ] gravar uma linha sem a coluna obrigatória do cliente (`fk_c_cliente`) falha com `QueryException`

#### Task 1.2 — Tabelas do Contingência BOT (`painel_contingency_crawler` e `informacao_tecnica_painel_contingencias`)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: duas migrations (`<data>_create_painel_contingency_crawler_table.php`, `<data>_create_informacao_tecnica_painel_contingencias_table.php`) com nomes de tabela e coluna idênticos ao 220 e os models `app/Models/PainelContingencyCrawler.php` e `app/Models/InformacaoTecnicaPainelContingencias.php`
- Testes críticos:
  - [ ] `tests/Migrations/TabelasContingencyCrawlerTest.php`: as duas tabelas existem com as colunas do 220 e os models fazem ida e volta de uma linha
  - [ ] a mesma configuração técnica (`fk_c_cliente`) não pode ser gravada duas vezes (índice único do 220 reproduzido)

#### Task 1.3 — Tabelas da Classificação Mercadológica (`painel_mercadological_worker` e `painel_mercadological_worker_log`)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: migrations `<data>_create_painel_mercadological_worker_table.php` e `<data>_create_painel_mercadological_worker_log_table.php` e os models `app/Models/PainelMercadologicalWorker.php` e `app/Models/PainelMercadologicalWorkerLog.php` (relação `hasMany` do painel para o log por `fk_c_cliente`)
- Testes críticos:
  - [ ] `tests/Migrations/TabelasMercadologicaTest.php`: colunas iguais às do 220 e o histórico de um cliente volta pela relação
  - [ ] log de cliente inexistente não aparece na relação de outro cliente

#### Task 1.4 — Tabela `painel_cleantax_worker` (CleanTax BOT)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE painel_cleantax_worker` no `testes220`
- Output: `database/migrations/<data>_create_painel_cleantax_worker_table.php` (com o `ON UPDATE CURRENT_TIMESTAMP` do 220) e `app/Models/PainelCleantaxWorker.php`
- Testes críticos:
  - [ ] `tests/Migrations/PainelCleantaxWorkerTest.php`: colunas iguais às do 220 e a coluna de atualização muda sozinha num `UPDATE`
  - [ ] gravar sem a coluna obrigatória do cliente falha com `QueryException`

#### Task 1.5 — Tabelas do Task Scheduler (`task_scheduler_querys` e `task_scheduler_querys_log`, menu Programação → Ferramentas)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: migrations `<data>_create_task_scheduler_querys_table.php` e `<data>_create_task_scheduler_querys_log_table.php` e os models `app/Models/TaskSchedulerQuery.php` e `app/Models/TaskSchedulerQueryLog.php` (`belongsTo` do log para a query)
- Testes críticos:
  - [ ] `tests/Migrations/TabelasTaskSchedulerTest.php`: colunas iguais às do 220 e o log aponta para a query certa
  - [ ] apagar uma query com logs segue a regra do 220 (bloqueia ou apaga em cascata — conforme a FK do `SHOW CREATE TABLE`)

#### Task 1.6 — Escrita controlada no Help Desk (desligada)
- Agent: Backend Laravel — segurança
- Input: aprovação do usuário para o walle gravar no Help Desk (hoje somente leitura — SPEC §4) e um usuário MySQL próprio no 192.168.10.254 com `GRANT` apenas em `s_atendimento`, `versionamentos`, `informacao_tecnica_dataserver`, `software_cliente` e `software_cliente_usuario`
- Output: conexão `help_escrita` em `config/database.php` (variáveis `HELP_ESCRITA_*` no `.env.example`, chave `walle.help_escrita.ativo` com `WALLE_HELP_ESCRITA_ATIVO=false`) e `app/Support/HelpDesk/EscritaHelpDesk.php` com `inserir(string $tabela, array $dados, string $acao, ?int $clienteId = null): int` e `atualizar(string $tabela, array $dados, array $onde, string $acao, ?int $clienteId = null): int`, que recusam tabela fora da lista permitida e chamam `Auditoria::registrar()` em toda escrita
- Testes críticos:
  - [ ] `tests/Feature/HelpDesk/EscritaHelpDeskTest.php`: com a escrita ligada, `inserir('s_atendimento', ...)` grava pela conexão `help_escrita` e cria um registro de `Auditoria` sem nenhum valor do campo de senha
  - [ ] tabela fora da lista (ex.: `a_user`) ou escrita desligada lança exceção e não toca o banco; a conexão `help` continua somente leitura

### Fase 2 — Importação dos dados locais do 220 · conclui com `docker exec walle-app ./vendor/bin/pest --filter=ImportarSuporte`
> Dependências: Fase 1
> Paralelismo: nenhum (uma task)

#### Task 2.1 — `walle:importar-suporte` com as novas tabelas
- Agent: Backend Laravel — dados
- Input: migrations das tasks 1.1 a 1.5
- Output: `app/Console/Commands/ImportarSuporte.php` com `painel_sentinela`, `painel_contingency_crawler`, `informacao_tecnica_painel_contingencias`, `painel_mercadological_worker`, `painel_mercadological_worker_log`, `painel_cleantax_worker`, `task_scheduler_querys` e `task_scheduler_querys_log` em `TABELAS`, na ordem que respeita as FKs
- Testes críticos:
  - [ ] `tests/Feature/ImportarSuporteTest.php`: importar só `painel_sentinela` copia as linhas da origem e rodar de novo não duplica
  - [ ] pedir uma tabela fora de `TABELAS` encerra com erro e não importa nada

## Sprint 2 — Todos os robôs que ainda rodam no 220 rodam no walle, desligados por padrão (`WALLE_*_ATIVO=false`), e gravam nas mesmas tabelas que o 220 (`docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias tests/Feature/Sentinela tests/Feature/Mercadologica tests/Feature/CleanTax tests/Feature/Ferramentas tests/Feature/Sactus` passa)

### Fase 3 — Robôs de coleta · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias/RoboContingenciasTest.php tests/Feature/Sentinela/RoboSentinelaTest.php tests/Feature/Mercadologica/RoboMercadologicaTest.php tests/Feature/CleanTax/RoboCleanTaxTest.php tests/Feature/Ferramentas/RoboTarefasTest.php`
> Dependências: Fase 2
> Paralelismo: 3.1, 3.2, 3.3, 3.4 e 3.5 rodam em paralelo (cada uma só cria arquivos dentro da sua pasta de domínio, um Job e os seus testes; nenhuma mexe em `routes/console.php` nem em `config/`)

#### Task 3.1 — Contingência BOT (substitui `contingency.crawler.php` e `set.history.cont.php`)
- Agent: Backend Laravel — robôs
- Input: tabelas da task 1.2, `history_contingencia` já existente e `ConectorDeCliente`/`ConexaoComCliente` de `app/Contingencias/Cliente`
- Output: `app/Contingencias/RoboContingencias.php` (`ligado(): bool`, `rodarCiclo(): void`, `coletarCliente(int $clienteId): ResultadoContingencias`), que lê as contingências de cada cliente e grava em `history_contingencia` e `painel_contingency_crawler`, e `app/Jobs/RodarCicloContingencias.php` (`ShouldBeUnique`, conexão `robos`)
- Testes críticos:
  - [ ] `tests/Feature/Contingencias/RoboContingenciasTest.php`: com um conector falso devolvendo 3 contingências, o ciclo grava 3 linhas em `history_contingencia` e atualiza a métrica do cliente em `painel_contingency_crawler`
  - [ ] cliente que falha na conexão (`FalhaConexaoCliente`) é registrado com o erro no painel e o ciclo segue para o próximo; com o robô desligado nada roda

#### Task 3.2 — Bot Sentinela (substitui `sentinela.php`)
- Agent: Backend Laravel — robôs
- Input: model da task 1.1
- Output: `app/Sentinela/RoboSentinela.php` (`ligado(): bool`, `rodarCiclo(): void`) que faz `GET http://<host>/sentinela.php?json=S` em cada cliente (timeout folgado, como no 220) e grava em `painel_sentinela`; `app/Sentinela/ColetaSentinela.php` (valor lido de um cliente); `app/Jobs/RodarCicloSentinela.php`
- Testes críticos:
  - [ ] `tests/Feature/Sentinela/RoboSentinelaTest.php`: com `Http::fake` devolvendo o JSON do sentinela, o painel do cliente fica igual ao que o 220 gravaria para o mesmo JSON
  - [ ] timeout ou JSON inválido grava o cliente como "sem resposta" com a mensagem, sem derrubar o ciclo

#### Task 3.3 — Classificação Mercadológica (substitui `mercadological.worker.php` e `set.mercadological.worker.php`)
- Agent: Backend Laravel — robôs
- Input: models da task 1.3
- Output: `app/Mercadologica/RoboMercadologica.php` (ciclo sequencial, um cliente por vez, só clientes com `executar_bot_class_mercadologica = 'S'`, quarentena depois de N falhas seguidas), `app/Mercadologica/SincronizacaoMercadologica.php` (`sincronizar(int $clienteId): ResultadoSincronizacao`) e `app/Jobs/RodarCicloMercadologica.php`
- Testes críticos:
  - [ ] `tests/Feature/Mercadologica/RoboMercadologicaTest.php`: cliente habilitado sem cadastro técnico aparece como aviso (não some em silêncio, correção do 220) e o cliente com cadastro é sincronizado e logado
  - [ ] cliente que falha N ciclos seguidos fica de fora pelos ciclos de quarentena e volta depois

#### Task 3.4 — CleanTax BOT (substitui `cleantax.php`)
- Agent: Backend Laravel — robôs
- Input: model da task 1.4
- Output: `app/CleanTax/RoboCleanTax.php` que busca os clientes na API `https://jmsistemas.net/cleantax`, chama o `cleantaxbot.php` de cada cliente com no máximo uma requisição por host ao mesmo tempo (`Cache::lock` por host, sem retry após timeout) e grava em `painel_cleantax_worker`; `app/Jobs/RodarCicloCleanTax.php`
- Testes críticos:
  - [ ] `tests/Feature/CleanTax/RoboCleanTaxTest.php`: com `Http::fake`, dois clientes no mesmo host nunca são chamados ao mesmo tempo e o painel registra início e fim de cada requisição
  - [ ] API do CleanTax fora do ar encerra o ciclo com erro registrado e sem gravar painel pela metade

#### Task 3.5 — Task Scheduler (substitui `task.scheduler.bot.php`)
- Agent: Backend Laravel — robôs
- Input: models da task 1.5 e `ConectorDeCliente`
- Output: `app/Ferramentas/RoboTarefas.php` (`rodarPendentes(): void`) que executa as queries agendadas de `task_scheduler_querys` no banco de cada cliente e grava o resultado em `task_scheduler_querys_log`; `app/Jobs/RodarTarefasAgendadas.php`
- Testes críticos:
  - [ ] `tests/Feature/Ferramentas/RoboTarefasTest.php`: uma query agendada para agora roda no cliente falso uma vez e gera um log com o resultado
  - [ ] erro de SQL no cliente vira log de erro e não reexecuta a query no mesmo ciclo

### Fase 4 — Robôs que gravam no Help Desk · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Sactus/SincronizacaoVpnTest.php tests/Feature/Sentinela/AlertaSentinelaTest.php tests/Feature/Mercadologica/AlertaMercadologicaTest.php`
> Dependências: Fase 1 (task 1.6) e Fase 3 (tasks 3.2 e 3.3)
> Paralelismo: 4.1, 4.2 e 4.3 rodam em paralelo (arquivos próprios em `app/Sactus`, `app/Sentinela` e `app/Mercadologica`)

#### Task 4.1 — Bot VPN (substitui `vpn.sync.inc.php`)
- Agent: Backend Laravel — robôs
- Input: `EscritaHelpDesk` (task 1.6) e o endereço do `status.log` publicado pelo painel Python da VPN
- Output: `app/Sactus/SincronizacaoVpn.php` (`sincronizar(bool $simular = false): ResultadoSincronizacaoVpn`) com as etapas do 220 (lê `CLIENT_LIST`, propaga o IP para os clientes do mesmo `unify_database`, compara com o banco, pinga só os que mudaram, grava só os que responderam) e `app/Jobs/SincronizarVpn.php`
- Testes críticos:
  - [ ] `tests/Feature/Sactus/SincronizacaoVpnTest.php`: com um `status.log` de exemplo, só os IPs que mudaram e responderam ao ping são atualizados no Help Desk, com auditoria
  - [ ] `simular: true` faz todas as etapas e não grava nada; `status.log` indisponível encerra com erro sem gravar

#### Task 4.2 — Alerta do Sentinela em atendimento (substitui `health.sentinela.worker.php`)
- Agent: Backend Laravel — robôs
- Input: `RoboSentinela` (task 3.2) e `EscritaHelpDesk` (task 1.6)
- Output: `app/Sentinela/AlertaSentinela.php` (`verificar(): int` — quantidade de atendimentos abertos) que lê `painel_sentinela` e abre `s_atendimento` no Help Desk com as mesmas regras do 220, sem abrir de novo para o mesmo problema já aberto
- Testes críticos:
  - [ ] `tests/Feature/Sentinela/AlertaSentinelaTest.php`: cliente com falha no sentinela gera um atendimento com o texto do 220
  - [ ] com atendimento já aberto para o mesmo cliente e problema, não abre outro; escrita no Help Desk desligada não abre nada

#### Task 4.3 — Alerta da Classificação Mercadológica em atendimento (substitui `health.mercadological.worker.php`)
- Agent: Backend Laravel — robôs
- Input: `RoboMercadologica` (task 3.3) e `EscritaHelpDesk` (task 1.6)
- Output: `app/Mercadologica/AlertaMercadologica.php` (`verificar(): int`) que lê `painel_mercadological_worker` e abre `s_atendimento` para cliente mudo ou com erro, com as regras do 220
- Testes críticos:
  - [ ] `tests/Feature/Mercadologica/AlertaMercadologicaTest.php`: cliente sem sinal há mais ciclos que o limite gera um atendimento
  - [ ] cliente em dia ou já com atendimento aberto não gera atendimento

### Fase 5 — Ligar os robôs no agendador e nas filas · conclui com `docker exec walle-app ./vendor/bin/pest --filter=Agendador && docker exec walle-app php artisan schedule:list`
> Dependências: Fase 3 e Fase 4
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 5.1 — Agenda, configuração e filas dos robôs novos
- Agent: Backend Laravel — infraestrutura
- Input: Jobs das tasks 3.1 a 3.5 e 4.1 a 4.3
- Output: entradas em `routes/console.php` (`walle:robo-contingencias`, `walle:robo-sentinela`, `walle:robo-mercadologica`, `walle:robo-cleantax`, `walle:robo-tarefas`, `walle:sincronizar-vpn`, `walle:alerta-sentinela`, `walle:alerta-mercadologica`, cada uma com `->when(fn ($robo) => $robo->ligado())`), blocos em `config/walle.php` com `WALLE_CONTINGENCIAS_ATIVO`, `WALLE_SENTINELA_ATIVO`, `WALLE_MERCADOLOGICA_ATIVO`, `WALLE_CLEANTAX_ATIVO`, `WALLE_TAREFAS_ATIVO`, `WALLE_VPN_ATIVO` (todos `false`), supervisores na conexão `robos` em `config/horizon.php` e as variáveis no `.env.example`
- Testes críticos:
  - [ ] `tests/Feature/AgendadorTest.php`: com cada `WALLE_*_ATIVO=true`, o evento do robô aparece como devido no agendador
  - [ ] com o robô desligado (padrão), o evento não dispara o Job

## Sprint 3 — Todas as telas do newsuporte que faltam abrem no walle, no padrão walle-ui, com os dados do banco `walle` (`docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php tests/Feature/Relatorios` passa)

### Fase 6 — Telas · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Relatorios tests/Feature/Contingencias/PainelRoboContingenciasTest.php tests/Feature/Sentinela/PainelSentinelaTest.php tests/Feature/Mercadologica/PainelMercadologicaTest.php tests/Feature/CleanTax/PainelCleanTaxTest.php tests/Feature/Ferramentas/PainelFerramentasTest.php tests/Feature/CrontabTest.php`
> Dependências: Fase 2 (dados) — as telas leem as tabelas e não dependem dos robôs estarem ligados
> Paralelismo: 6.1, 6.2, 6.3, 6.4, 6.5, 6.6 e 6.7 rodam em paralelo (cada uma cria o seu componente Livewire, as suas views e o seu teste; rotas e menu ficam para a Fase 7)

#### Task 6.1 — Relatórios (substitui `consultorias/relatorios`: Clientes, Contingência NFC-e, Motivos)
- Agent: Frontend Livewire (walle-ui)
- Input: `history_contingencia` (walle) e `c_cliente` (conexão `help`)
- Output: `app/Livewire/Relatorios.php` com `ABAS` `clientes`, `contingencia`, `motivos` e `resources/views/livewire/relatorios/{clientes,contingencia,motivos}.blade.php`; Motivos agrupa por semana (segunda a domingo, como `get.motivos.contingencias.php`) com `<x-grafico.barras>` e a tabela dos mesmos números
- Testes críticos:
  - [ ] `tests/Feature/Relatorios/RelatoriosTest.php`: com contingências semeadas, a aba Motivos mostra a mesma contagem por motivo que o 220 mostra para a mesma semana
  - [ ] período sem dados mostra `<x-ui.vazio>` e aba inválida na URL volta para `clientes`

#### Task 6.2 — Painel do Contingência BOT (substitui `contingency.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: models da task 1.2
- Output: `app/Livewire/Contingencias/Robo.php` e `resources/views/livewire/contingencias/robo.blade.php` (KPIs, clientes com última coleta e erro), com a aba `robo` acrescentada em `App\Livewire\Contingencias::ABAS`
- Testes críticos:
  - [ ] `tests/Feature/Contingencias/PainelRoboContingenciasTest.php`: a aba lista os clientes de `painel_contingency_crawler` com a última coleta
  - [ ] cliente com erro aparece com `<x-ui.etiqueta cor="erro">` e o robô desligado mostra o aviso de desligado

#### Task 6.3 — Painel do Sentinela (substitui `sentinela.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: model da task 1.1
- Output: `app/Livewire/Sentinela.php` (filtro `#[Url] $cliente`, como o `?id=` do 220) e `resources/views/livewire/sentinela.blade.php`
- Testes críticos:
  - [ ] `tests/Feature/Sentinela/PainelSentinelaTest.php`: a tela mostra cada cliente com o estado coletado e filtra pelo cliente da URL
  - [ ] cliente inexistente na URL mostra o vazio, sem erro

#### Task 6.4 — Painel da Classificação Mercadológica (substitui `mercadological.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: models da task 1.3
- Output: `app/Livewire/Mercadologica.php` e `resources/views/livewire/mercadologica.blade.php` (KPIs, clientes, histórico do cliente aberto, "sem sinal" pela régua de ciclos do 220)
- Testes críticos:
  - [ ] `tests/Feature/Mercadologica/PainelMercadologicaTest.php`: os KPIs batem com a contagem das linhas semeadas e o histórico abre o cliente certo
  - [ ] cliente que passou da régua de ciclos aparece como "sem sinal"

#### Task 6.5 — Painel do CleanTax (substitui `cleantax.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: model da task 1.4
- Output: `app/Livewire/CleanTax.php` e `resources/views/livewire/clean-tax.blade.php`
- Testes críticos:
  - [ ] `tests/Feature/CleanTax/PainelCleanTaxTest.php`: lista as execuções da iteração com início, fim e resultado
  - [ ] sem nenhuma execução mostra o vazio

#### Task 6.6 — Ferramentas: Task Scheduler e relatório (substitui `programacao/tools` e `programacao/tools/reports`)
- Agent: Frontend Livewire (walle-ui)
- Input: models da task 1.5
- Output: `app/Livewire/Ferramentas.php` com `ABAS` `querys` e `relatorio` (criar, editar, ativar/desativar queries — só admin com `ConfirmaSenha`, métodos em `ACOES_COM_SENHA`) e `resources/views/livewire/ferramentas/{querys,relatorio}.blade.php`
- Testes críticos:
  - [ ] `tests/Feature/Ferramentas/PainelFerramentasTest.php`: admin cria uma query depois de confirmar a senha e ela aparece na lista com `Auditoria`
  - [ ] usuário que não é admin recebe `403` ao salvar

#### Task 6.7 — Crontab (substitui `crontab/index.php`, Cron Control Center)
- Agent: Frontend Livewire (walle-ui)
- Input: eventos do agendador (`Illuminate\Console\Scheduling\Schedule`)
- Output: `app/Livewire/Crontab.php` e `resources/views/livewire/crontab.blade.php` com cada rotina do `routes/console.php` (nome, frequência, próxima execução, ligada/desligada)
- Testes críticos:
  - [ ] `tests/Feature/CrontabTest.php`: as rotinas `walle:robo-autorizadas` e `walle:robo-sactus` aparecem com a frequência certa
  - [ ] robô desligado no `.env` aparece como "desligado"

### Fase 7 — Rotas, menu e saúde das telas novas · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php && docker exec walle-app php artisan route:list --except-vendor`
> Dependências: Fase 5 e Fase 6
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 7.1 — Registrar as telas novas
- Agent: Backend Laravel — infraestrutura
- Input: componentes das tasks 6.1 a 6.7 e robôs da Fase 5
- Output: rotas `relatorios`, `sentinela`, `mercadologica`, `clean-tax`, `ferramentas` e `crontab` em `routes/web.php` (grupo `auth`), itens com `<x-ui.menu-item>` em `resources/views/layouts/app.blade.php` (Programação só para admin) e um item por robô novo em `App\Livewire\Saude`
- Testes críticos:
  - [ ] `tests/Feature/TelasTest.php`: cada rota nova abre (`200`) para usuário logado e a Saúde mostra os robôs novos como "Desligado neste servidor"
  - [ ] visitante é redirecionado para `entrar`; usuário que não é admin não vê o menu Programação

## Sprint 4 — Toda ação que o newsuporte fazia fora do walle existe no walle, só para admin, com senha confirmada (`ConfirmaSenha`) e registro em `Auditoria` (`docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias tests/Feature/Ferramentas tests/Feature/Sactus tests/Feature/Portas tests/Feature/Conversor tests/Feature/InformacoesTecnicas tests/Feature/Updates` passa)

### Fase 8 — Ações nos servidores dos clientes e no banco `walle` · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias/AcoesContingenciasTest.php tests/Feature/Contingencias/ListaDeClientesAcoesTest.php tests/Feature/Ferramentas/ExecucaoDeQueryTest.php tests/Feature/InformacoesTecnicas`
> Dependências: Fase 7
> Paralelismo: 8.1, 8.2, 8.3 e 8.4 rodam em paralelo (8.1 só mexe em `Contingencias\DoCliente`, 8.2 só em `Livewire\Contingencias` e na lista, 8.3 só em `app/Ferramentas` e `Livewire\Ferramentas`, 8.4 cria o seu módulo)

#### Task 8.1 — Ações de contingência do cliente (substitui `findSale`, `deleteContAdm`, `atualizaAliquotas`, `reprocessCont` e `getCrontab` do `contingencias.js`)
- Agent: Backend Laravel — robôs
- Input: `ConectorDeCliente`, tela `Contingencias\DoCliente`
- Output: `app/Contingencias/AcoesContingencias.php` com `reenviarAoPdv(int $clienteId, array $cupons): ResultadoAcao`, `removerDaListagem(int $clienteId, array $chaves): int`, `atualizarAliquotas(int $clienteId): ResultadoAcao`, `reprocessar(int $clienteId): ResultadoAcao` e `crontabDoCliente(int $clienteId): string`; botões em `app/Livewire/Contingencias/DoCliente.php` (métodos em `ACOES_COM_SENHA`)
- Testes críticos:
  - [ ] `tests/Feature/Contingencias/AcoesContingenciasTest.php`: admin com senha confirmada reenvia 2 cupons ao PDV (conector falso recebe o `UPDATE contingencias_versatus` do 220) e a ação fica em `Auditoria`
  - [ ] sem senha confirmada ou sem ser admin nada é enviado; falha de conexão mostra o erro sem alterar a tela

#### Task 8.2 — Remover cliente da lista de contingências (substitui `delete.php`)
- Agent: Backend Laravel — dados
- Input: `contingencias_versatus_venda` (walle) e tela `Contingencias`
- Output: `app/Contingencias/RemocaoDeCliente.php` (`remover(int $clienteId): int` — linhas apagadas de `contingencias_versatus_venda` e itens, numa transação) e o botão na lista de `app/Livewire/Contingencias.php` (admin + `ConfirmaSenha`)
- Testes críticos:
  - [ ] `tests/Feature/Contingencias/ListaDeClientesAcoesTest.php`: remover um cliente apaga só as vendas e itens dele e registra `Auditoria`
  - [ ] id inválido (zero, negativo ou inexistente) não apaga nada e avisa

#### Task 8.3 — Execução de query e ferramentas de debug (substitui `execute.query.php`, `execute.query.available.php` e `debugs.tools.php`)
- Agent: Backend Laravel — segurança
- Input: `ConectorDeCliente` e tela `Ferramentas` (task 6.6)
- Output: `app/Ferramentas/ExecucaoDeQuery.php` (`executar(int $clienteId, string $sql): ResultadoQuery`, só `SELECT` sem ser admin; escrita só admin com senha) e a aba `executar` em `App\Livewire\Ferramentas`
- Testes críticos:
  - [ ] `tests/Feature/Ferramentas/ExecucaoDeQueryTest.php`: um `SELECT` no cliente falso devolve as linhas e registra `Auditoria` com o SQL (sem valores sensíveis)
  - [ ] `UPDATE`/`DELETE` sem ser admin com senha é recusado antes de conectar no cliente

#### Task 8.4 — Informações Técnicas do cliente (substitui `consultorias/config`: Configurações do Servidor e de PDV, `config/api/import.php`, `sets.php`, `gets.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: tabela `informacao_tecnica_painel_contingencias` (task 1.2) e `c_cliente` (conexão `help`)
- Output: `app/InformacoesTecnicas/ConfiguracaoTecnica.php` (`salvar(int $clienteId, array $dados): void`, `importarDoHelpDesk(int $clienteId): void`) e `app/Livewire/InformacoesTecnicas.php` com `resources/views/livewire/informacoes-tecnicas.blade.php` (formulário com `<x-ui.campo>`)
- Testes críticos:
  - [ ] `tests/Feature/InformacoesTecnicas/InformacoesTecnicasTest.php`: salvar as configurações de servidor e PDV grava a linha do cliente e reabrir mostra os mesmos valores
  - [ ] campo obrigatório vazio ou porta fora do intervalo mostra o erro de validação e não grava

### Fase 9 — Ações que gravam no Help Desk · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Sactus/VersoesSactusTest.php tests/Feature/Portas tests/Feature/Contingencias/SoftwareHelpDeskTest.php`
> Dependências: Fase 1 (task 1.6 aprovada e ligada) e Fase 8
> Paralelismo: 9.1, 9.2 e 9.3 rodam em paralelo (9.1 só mexe em `app/Sactus` e `Livewire\Sactus`, 9.2 cria o módulo Portas, 9.3 só mexe em `app/Contingencias/SoftwareHelpDesk.php` e no seu botão)

#### Task 9.1 — Versões Sactus (substitui `crontab/versoes_sactus.php`, Deploy Sactus)
- Agent: Backend Laravel — robôs
- Input: `EscritaHelpDesk` (task 1.6)
- Output: `app/Sactus/VersoesSactus.php` (`adicionar(string $versao, string $descricao): int` em `versionamentos`) e `app/Livewire/Sactus/Versoes.php` com a aba `versoes` em `App\Livewire\Sactus::ABAS`
- Testes críticos:
  - [ ] `tests/Feature/Sactus/VersoesSactusTest.php`: admin adiciona uma versão e ela aparece na lista lida do Help Desk, com `Auditoria`
  - [ ] versão repetida ou vazia é recusada sem gravar

#### Task 9.2 — Portas (substitui `portas.php` e `api_portas.php`)
- Agent: Backend Laravel — robôs
- Input: `EscritaHelpDesk` (task 1.6) e a lista de portas usada hoje pelo `portas.php`
- Output: `app/Portas/AtualizacaoDePortas.php` (`aplicar(array $linhas): ResultadoPortas`, extrai o id de `srv-cli-NNNN` e a primeira porta de `9396 - 9406`, atualiza `servidor_docker` e `range_pdv_de` em `informacao_tecnica_dataserver`) e `app/Livewire/Portas.php` com `resources/views/livewire/portas.blade.php`
- Testes críticos:
  - [ ] `tests/Feature/Portas/AtualizacaoDePortasTest.php`: as linhas `srv-cli-1193` / `9396 - 9406` viram cliente 1193 e porta 9396 e só os clientes que mudaram são atualizados
  - [ ] linha em formato inválido é ignorada e listada como erro, sem atualizar nada dela

#### Task 9.3 — Software do cliente no Help Desk (substitui `update.software.help.desk.php`)
- Agent: Backend Laravel — dados
- Input: `EscritaHelpDesk` (task 1.6) e tela `Contingencias\DoCliente`
- Output: `app/Contingencias/SoftwareHelpDesk.php` (`atualizar(int $clienteId): ResultadoAcao`, grava `software_cliente` e `software_cliente_usuario` como o 220) e o botão em `Contingencias\DoCliente`
- Testes críticos:
  - [ ] `tests/Feature/Contingencias/SoftwareHelpDeskTest.php`: cliente sem `software_cliente` ganha o cabeçalho e os usuários, com `Auditoria`
  - [ ] rodar de novo não duplica cabeçalho nem usuários

### Fase 10 — Ferramentas auxiliares · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Conversor tests/Feature/Updates`
> Dependências: Fase 7
> Paralelismo: 10.1 e 10.2 rodam em paralelo (módulos próprios)

#### Task 10.1 — Conversor de certificado (substitui `menu/conversor.php`)
- Agent: Backend Laravel — segurança
- Input: nenhuma além da Fase 7
- Output: `app/Conversor/ConversorCertificado.php` (`converter(string $conteudoPfx, string $senha): CertificadoConvertido`, tudo em memória, nada gravado em disco nem em log) e `app/Livewire/Conversor.php` com upload temporário apagado depois da conversão
- Testes críticos:
  - [ ] `tests/Feature/Conversor/ConversorCertificadoTest.php`: um `.pfx` de teste com a senha certa vira PEM com certificado e chave, e `Auditoria` registra a conversão sem a senha
  - [ ] senha errada ou arquivo que não é `.pfx` mostra o erro e não deixa arquivo temporário

#### Task 10.2 — Updates (substitui `programacao/updates`, árvore de `sistema/app`)
- Agent: Frontend Livewire (walle-ui)
- Input: decisão de onde o `sistema/app` do 220 fica acessível ao contêiner `walle-app` (montagem só leitura) e a chave `walle.updates.raiz`
- Output: `app/Livewire/Updates.php` e `resources/views/livewire/updates.blade.php` (árvore só leitura a partir de `walle.updates.raiz`, com `realpath` conferindo que o caminho não sai da raiz)
- Testes críticos:
  - [ ] `tests/Feature/Updates/UpdatesTest.php`: com uma raiz de teste, a árvore lista pastas e arquivos na ordem certa
  - [ ] caminho com `../` ou fora da raiz mostra "Caminho inválido" e não lista nada

### Fase 11 — Rotas e menu das telas do Sprint 4 · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php`
> Dependências: Fase 8, Fase 9 e Fase 10
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 11.1 — Registrar Informações Técnicas, Portas, Conversor e Updates
- Agent: Backend Laravel — infraestrutura
- Input: componentes das tasks 8.4, 9.2, 10.1 e 10.2
- Output: rotas `informacoes-tecnicas`, `portas`, `conversor` e `updates` em `routes/web.php` e itens no menu Programação de `resources/views/layouts/app.blade.php`
- Testes críticos:
  - [ ] `tests/Feature/TelasTest.php`: as quatro rotas abrem para admin logado
  - [ ] usuário que não é admin recebe `403` nas quatro

## Sprint 5 — Com os mesmos dados, cada módulo migrado devolve o mesmo resultado que o newsuporte e os robôs do 220 podem ser desligados (`docker exec walle-app ./vendor/bin/pest tests/Feature/Paridade` passa)

### Fase 12 — Paridade com o newsuporte · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Paridade`
> Dependências: Sprints 1 a 4
> Paralelismo: 12.1, 12.2, 12.3 e 12.4 rodam em paralelo (cada uma cria só o seu teste e as suas amostras em `tests/Fixtures/legado/<modulo>/`)

#### Task 12.1 — Paridade dos robôs de coleta (Contingência BOT, Sentinela, Classificação Mercadológica, CleanTax)
- Agent: QA — paridade com o newsuporte
- Input: respostas reais de clientes capturadas no `testes220` (JSON do sentinela, contingências, retorno do CleanTax) e as linhas que o 220 gravou para elas
- Output: `tests/Feature/Paridade/RobosDeColetaParidadeTest.php` e amostras em `tests/Fixtures/legado/robos/`
- Testes críticos:
  - [ ] para cada amostra, o robô do walle grava nas tabelas exatamente as mesmas linhas que o 220 gravou
  - [ ] amostra de cliente com erro produz o mesmo estado de erro nos dois sistemas

#### Task 12.2 — Paridade dos relatórios
- Agent: QA — paridade com o newsuporte
- Input: um recorte de `history_contingencia` e `c_cliente` exportado do `testes220` e os números que as telas de relatório do 220 mostram para ele
- Output: `tests/Feature/Paridade/RelatoriosParidadeTest.php` e amostras em `tests/Fixtures/legado/relatorios/`
- Testes críticos:
  - [ ] Clientes, Contingência NFC-e e Motivos mostram os mesmos totais do 220 para o mesmo período
  - [ ] semana sem contingências mostra zero nos dois

#### Task 12.3 — Paridade das escritas no Help Desk (VPN, alertas, versões, portas, software)
- Agent: QA — paridade com o newsuporte
- Input: entradas e o resultado gravado pelo 220 em `s_atendimento`, `versionamentos`, `informacao_tecnica_dataserver`, `software_cliente` e `software_cliente_usuario`
- Output: `tests/Feature/Paridade/EscritasHelpDeskParidadeTest.php` e amostras em `tests/Fixtures/legado/help-desk/`
- Testes críticos:
  - [ ] com as mesmas entradas, o walle gera os mesmos `INSERT`/`UPDATE` (mesmas colunas e valores) que o 220
  - [ ] entrada que o 220 ignorava também é ignorada pelo walle

#### Task 12.4 — Paridade das ações de contingência e ferramentas
- Agent: QA — paridade com o newsuporte
- Input: comandos que o 220 enviou aos clientes (reenviar ao PDV, alíquotas, queries agendadas) capturados no `testes220`
- Output: `tests/Feature/Paridade/AcoesParidadeTest.php` e amostras em `tests/Fixtures/legado/acoes/`
- Testes críticos:
  - [ ] para cada ação, o conector falso do walle recebe exatamente o mesmo SQL ou comando que o 220 enviou
  - [ ] ação recusada no walle (sem senha, sem admin) não envia nada ao cliente
