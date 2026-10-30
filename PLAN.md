# PLAN.md

> `.ai/guidelines/walle.md` é a fonte central. Decisões de 24/10/2026: nenhuma escrita no Help Desk; auditoria também nas operações automáticas abrangidas; SQL manual, inclusive SELECT, só para admin com confirmação válida na sessão; certificados nunca em disco.
> Revisão documental de escopo, sem alterar o código existente. Funcionalidades de escrita no Help Desk foram retiradas do plano. A numeração restante foi preservada para manter referências estáveis.
> Comandos filtrados abaixo são verificações intermediárias. Para concluir alterações de código, o agente executa Pint nos PHP alterados, PHPStan completo e toda a suíte Pest.
> Paridade com o 220 vale apenas para funcionalidades mantidas no escopo e ressalva desvios explicitamente aprovados; não reintroduz escritas no Help Desk.

## Infraestrutura de desenvolvimento — RAG local dos agentes

Pedido explícito do usuário: capturar aprendizados ao terminar agente/sessão e recuperá-los no
prompt seguinte. Resumo extrativo local, sem modelos pagos, aprovado no chat. Independente das
fases de migração do 220; não altera sua numeração nem autoriza tarefas funcionais adicionais.

- [x] `.claude/scripts/schema.sql`: SQLite com sqlite-vec, metadados dos chunks e reindexação atômica.
- [x] `.claude/scripts/summarize.ts`: quatro categorias, escopo por agente/sessão e sanitização.
- [x] `.claude/scripts/embed.ts`: chunks pelo tokenizer e embeddings locais all-MiniLM-L6-v2.
- [x] `.claude/scripts/search.ts`: top-3, preferência configurável de categoria e contexto delimitado.
- [x] Hooks `SubagentStop`/`Stop` assíncronos e `UserPromptSubmit` síncrono, preservando os hooks anteriores.
- [x] Testes isolados de extração, indexação, recuperação, isolamento e modelo real; typecheck dos scripts.
- [x] PHPStan completo e suíte Pest completa no Docker; relatar falhas preexistentes separadamente.
  - Execução inicial de 24/10/2026: PHPStan completo sem erros; Pest completo com `tests/Unit`, `tests/Feature` e `tests/Migrations`: 227 passaram, 9 falharam (938 assertions). Falhas em `tests/Feature/Contingencias/RoboContingenciasTest.php`: métodos ausentes no robô e Job sem `ShouldBeUnique`, artefatos da task 3.1 anterior à implementação do RAG. Nenhum PHP foi alterado pelo RAG; naquela execução essas falhas ficaram pendentes.
  - Retomada de 24/10/2026, após implementar a task 3.1: Pint dos quatro PHP da task e PHPStan completo passaram; `docker exec -e DB_DATABASE=walle_testes walle-app ./vendor/bin/pest tests/Unit tests/Feature tests/Migrations --compact` terminou com exit 0, `243 passed (1033 assertions)`, em 257,54 s. As nove falhas anteriores foram encerradas; o RAG não exigiu alterações adicionais.


### T-0008 — Painel do cérebro do RAG

Pedido explícito de 27/10/2026. Painel interno em /conhecimento, com rota e menu para admin,
leitura do índice existente e grafo de similaridade. Nenhuma alteração na captura dos agentes.

Entregue no mesmo PR da T-0009 por decisão do usuário de 27/10/2026: o trabalho das duas tarefas
ficou na mesma árvore e o usuário escolheu integrá-lo junto, em vez de separar os branches.

- [x] Leitura somente leitura do SQLite existente, incluindo dados recentes do WAL, sem criar o banco ausente.
- [x] Memórias paginadas, agentes, categorias, última indexação e filtros com seleção do conteúdo escapado.
- [x] Grafo com conexões por similaridade real dos embeddings, limites explícitos e alternativa acessível.
- [x] Autorização de admin na rota e nas interações; índice indisponível e filtros sem resultados tratados.
- [x] Pint, PHPStan, testes do módulo, build e gate completo válido para o commit; revisão e CI conferidos pelo `integrar` da T-0009.

## Sprint 1 — Tabelas locais do 220 e importação dos dados (`docker exec walle-app ./vendor/bin/pest tests/Migrations tests/Feature/ImportarSuporteTest.php` passa)

### Fase 1 — Tabelas locais do 220 · verificação: `docker exec walle-app ./vendor/bin/pest tests/Migrations`
> Dependências: nenhuma
> Paralelismo: 1.1, 1.2, 1.3, 1.4 e 1.5 rodam em paralelo (cada uma cria só os seus arquivos de migration, model e teste)

#### Task 1.1 — Tabela `painel_sentinela` (Bot Sentinela)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE painel_sentinela` do MySQL do contêiner `testes220`
- Output: `database/migrations/<data>_create_painel_sentinela_table.php` com exatamente as mesmas colunas e índices do 220 (collation `utf8mb4_general_ci`) e `app/Models/PainelSentinela.php` (`protected $table = 'painel_sentinela'`, `$timestamps` conforme a tabela, casts de data/JSON)
- Testes críticos:
  - [x] `tests/Migrations/PainelSentinelaTest.php`: depois de `migrate`, a tabela tem a mesma lista de colunas do 220 (lista fixada no teste) e o model grava e lê uma linha
  - [x] gravar uma linha sem a coluna obrigatória do cliente (`fk_c_cliente`) falha com `QueryException`

#### Task 1.2 — Tabelas do Contingência BOT (`painel_contingency_crawler` e `informacao_tecnica_painel_contingencias`)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: duas migrations (`<data>_create_painel_contingency_crawler_table.php`, `<data>_create_informacao_tecnica_painel_contingencias_table.php`) com nomes de tabela e coluna idênticos ao 220 e os models `app/Models/PainelContingencyCrawler.php` e `app/Models/InformacaoTecnicaPainelContingencias.php`
- Testes críticos:
  - [x] `tests/Migrations/TabelasContingencyCrawlerTest.php`: as duas tabelas existem com as colunas do 220 e os models fazem ida e volta de uma linha
  - [x] a mesma configuração técnica (`fk_c_cliente`) não pode ser gravada duas vezes (índice único do 220 reproduzido)

#### Task 1.3 — Tabelas da Classificação Mercadológica (`painel_mercadological_worker` e `painel_mercadological_worker_log`)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: migrations `<data>_create_painel_mercadological_worker_table.php` e `<data>_create_painel_mercadological_worker_log_table.php` e os models `app/Models/PainelMercadologicalWorker.php` e `app/Models/PainelMercadologicalWorkerLog.php` (relação `hasMany` do painel para o log por `fk_c_cliente`)
- Testes críticos:
  - [x] `tests/Migrations/TabelasMercadologicaTest.php`: colunas iguais às do 220 e o histórico de um cliente volta pela relação
  - [x] log de cliente inexistente não aparece na relação de outro cliente

#### Task 1.4 — Tabela `painel_cleantax_worker` (CleanTax BOT)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE painel_cleantax_worker` no `testes220`
- Output: `database/migrations/<data>_create_painel_cleantax_worker_table.php` (com o `ON UPDATE CURRENT_TIMESTAMP` do 220) e `app/Models/PainelCleantaxWorker.php`
- Testes críticos:
  - [x] `tests/Migrations/PainelCleantaxWorkerTest.php`: colunas iguais às do 220 e a coluna de atualização muda sozinha num `UPDATE`
  - [x] gravar sem a coluna obrigatória do cliente falha com `QueryException`

#### Task 1.5 — Tabelas do Task Scheduler (`task_scheduler_querys` e `task_scheduler_querys_log`, menu Programação → Ferramentas)
- Agent: Backend Laravel — dados
- Input: `SHOW CREATE TABLE` das duas tabelas no `testes220`
- Output: migrations `<data>_create_task_scheduler_querys_table.php` e `<data>_create_task_scheduler_querys_log_table.php` e os models `app/Models/TaskSchedulerQuery.php` e `app/Models/TaskSchedulerQueryLog.php` (`belongsTo` do log para a query)
- Testes críticos:
  - [x] `tests/Migrations/TabelasTaskSchedulerTest.php`: colunas iguais às do 220 e o log aponta para a query certa
  - [x] apagar uma query com logs segue a regra do 220 (bloqueia ou apaga em cascata — conforme a FK do `SHOW CREATE TABLE`)

### Fase 2 — Importação dos dados locais do 220 · conclui com `docker exec walle-app ./vendor/bin/pest --filter=ImportarSuporte`
> Dependências: Fase 1
> Paralelismo: nenhum (uma task)

#### Task 2.1 — `walle:importar-suporte` com as novas tabelas
- Agent: Backend Laravel — dados
- Input: migrations das tasks 1.1 a 1.5
- Output: `app/Console/Commands/ImportarSuporte.php` com `painel_sentinela`, `painel_contingency_crawler`, `informacao_tecnica_painel_contingencias`, `painel_mercadological_worker`, `painel_mercadological_worker_log`, `painel_cleantax_worker`, `task_scheduler_querys` e `task_scheduler_querys_log` em `TABELAS`, na ordem que respeita as FKs
- Testes críticos:
  - [x] `tests/Feature/ImportarSuporteTest.php`: importar só `painel_sentinela` copia as linhas da origem e rodar de novo não duplica
  - [x] pedir uma tabela fora de `TABELAS` encerra com erro e não importa nada

## Sprint 2 — Os robôs do 220 mantidos no escopo rodam no walle, desligados por padrão (`WALLE_*_ATIVO=false`), e gravam nas mesmas tabelas que o 220 (`docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias tests/Feature/Sentinela tests/Feature/CleanTax tests/Feature/Ferramentas tests/Feature/Sactus` passa)

### Fase 3 — Robôs de coleta · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias/RoboContingenciasTest.php tests/Feature/Sentinela/RoboSentinelaTest.php tests/Feature/CleanTax/RoboCleanTaxTest.php tests/Feature/Ferramentas/RoboTarefasTest.php`
> Dependências: Fase 2
> Fora desta fase: a task 3.3 (Classificação Mercadológica) foi para a seção manual no fim do plano (decisão de 26/10/2026).
> Paralelismo: 3.1, 3.2, 3.4 e 3.5 rodam em paralelo (cada uma só cria arquivos dentro da sua pasta de domínio, um Job e os seus testes; nenhuma mexe em `routes/console.php` nem em `config/`)

#### Task 3.0 — Histórico local dos ciclos e coletas de contingências
- Agent: Backend Laravel — dados
- Decisão aprovada no chat em 24/10/2026: criar duas tabelas próprias do walle antes da task 3.1, sem alterar a estrutura das tabelas herdadas nem gravar no Help Desk.
- Output: migrations de `contingencias_ciclos` e `contingencias_coletas`, models `CicloContingencias` e `ColetaContingencias` e associação da última coleta em `PainelContingencyCrawler`.
- Comportamento: registrar início, término, estado e contagens do ciclo; cada coleta registra cliente, ciclo, estado, totais e erro sanitizado. Falha mantém a última métrica válida, com a desatualização identificável pela última coleta; sucesso posterior não apaga o histórico de falhas. Nenhuma FK aponta ao Help Desk e nenhuma retenção automática é acrescentada.
- Testes críticos:
  - [x] `tests/Feature/Contingencias/ColetasContingenciasTest.php`: ciclos e coletas persistem somente no banco local, com relações e distinção entre falha e total zero.
  - [x] última coleta é isolada por cliente, mantendo o histórico anterior e a estrutura legada do painel.

#### Task 3.1 — Contingência BOT (substitui `contingency.crawler.php` e `set.history.cont.php`)
- Agent: Backend Laravel — robôs
- Input: tabelas da task 1.2, histórico local da task 3.0, `history_contingencia` já existente e `ConectorDeCliente`/`ConexaoComCliente` de `app/Contingencias/Cliente`
- Output: `app/Contingencias/RoboContingencias.php` (`ligado(): bool`, `rodarCiclo(): void`, `coletarCliente(int $clienteId): ResultadoContingencias`), que lê as contingências de cada cliente e grava em `history_contingencia` e `painel_contingency_crawler`, e `app/Jobs/RodarCicloContingencias.php` (`ShouldBeUnique`, conexão `robos`)
- Testes críticos:
  - [x] `tests/Feature/Contingencias/RoboContingenciasTest.php`: com um conector falso devolvendo 3 contingências, o ciclo grava 3 linhas em `history_contingencia` e atualiza a métrica do cliente em `painel_contingency_crawler`
  - [x] cliente que falha na conexão (`FalhaConexaoCliente`) tem erro sanitizado registrado na coleta local associada ao painel, preservando as métricas e o histórico válidos, e o ciclo segue para o próximo; com o robô desligado nada roda
- Verificação em 24/10/2026: 16 cenários do robô; Pint e PHPStan completo verdes; suíte completa com `tests/Unit`, `tests/Feature` e `tests/Migrations`: `243 passed (1033 assertions)`, 257,54 s. Agenda/configuração/ativação continuam fora desta task.
- Revisão final de `walle-arquiteto`: VERDE, sem achados BLOQUEANTE ou IMPORTANTE. Revisão somente leitura; gates executados por `walle-robos`. Paridade herdada de Sactus e unicidade global de `history_contingencia.chave` preservadas; sem validação contra serviços reais.
- Documentação da entrega: [relatório técnico](docs/features/2026-10-24-contingencia-bot-task-3-1/relatorio-tecnico.md) e [relatório de uso](docs/features/2026-10-24-contingencia-bot-task-3-1/relatorio-uso.md), complementados a pedido do usuário em 24/10/2026; aceite final do usuário permanece pendente.
- Revisão documental de `walle-arquiteto`: VERDE, sem problemas materiais; 14 links locais conferidos. Revisão de consistência sem executar a aplicação novamente, separada do parecer da implementação.

#### Task 3.2 — Bot Sentinela (substitui `sentinela.php`)
- Agent: Backend Laravel — robôs
- Input: model da task 1.1
- Output: `app/Sentinela/RoboSentinela.php` (`ligado(): bool`, `rodarCiclo(): void`) que faz `GET http://<host>/sentinela.php?json=S` em cada cliente (timeout com valor e unidade documentados a partir do script do 220) e grava em `painel_sentinela`; `app/Sentinela/ColetaSentinela.php` (valor lido de um cliente); `app/Jobs/RodarCicloSentinela.php`
- Testes críticos:
  - [x] `tests/Feature/Sentinela/RoboSentinelaTest.php`: com `Http::fake` devolvendo o JSON do sentinela, o painel do cliente fica igual ao que o 220 gravaria para o mesmo JSON
  - [x] timeout ou JSON inválido grava o cliente como "sem resposta" com a mensagem, sem derrubar o ciclo

#### Task 3.4 — CleanTax BOT (substitui `cleantax.php`)
- Agent: Backend Laravel — robôs
- Input: model da task 1.4
- Output: `app/CleanTax/RoboCleanTax.php` que busca os clientes na API `https://jmsistemas.net/cleantax`, chama o `cleantaxbot.php` de cada cliente com no máximo uma requisição por host ao mesmo tempo (`Cache::lock` por host, sem retry após timeout) e grava em `painel_cleantax_worker`; `app/Jobs/RodarCicloCleanTax.php`
- Testes críticos:
  - [x] `tests/Feature/CleanTax/RoboCleanTaxTest.php`: com `Http::fake`, dois clientes no mesmo host nunca são chamados ao mesmo tempo e o painel registra início e fim de cada requisição
  - [x] API do CleanTax fora do ar encerra o ciclo com erro registrado e sem gravar painel pela metade

#### Task 3.5 — Task Scheduler (substitui `task.scheduler.bot.php`)
- Agent: Backend Laravel — robôs
- Input: models da task 1.5 e `ConectorDeCliente`
- Output: `app/Ferramentas/RoboTarefas.php` (`rodarPendentes(): void`) que executa as queries agendadas de `task_scheduler_querys` no banco de cada cliente e grava o resultado em `task_scheduler_querys_log`; `app/Jobs/RodarTarefasAgendadas.php`
- Testes críticos:
  - [x] `tests/Feature/Ferramentas/RoboTarefasTest.php`: uma query agendada para agora roda no cliente falso uma vez e gera um log com o resultado
  - [x] erro de SQL no cliente vira log de erro e não reexecuta a query no mesmo ciclo
- Entregue na T-0009, junto com o painel da T-0008 (decisão do usuário de 27/10/2026). Agenda, configuração (`walle.tarefas.*`) e fila ficam para a task 5.1.

### Fase 5 — Ligar os robôs no agendador e nas filas · conclui com `docker exec walle-app ./vendor/bin/pest --filter=Agendador && docker exec walle-app php artisan schedule:list`
> Dependências: Fase 3
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 5.1 — Agenda, configuração e filas dos robôs novos
- Agent: Backend Laravel — infraestrutura
- Input: Jobs das tasks 3.1, 3.2, 3.4 e 3.5 (a Mercadológica fica de fora); valores exatos de frequência, timeout, limite de falhas e duração da quarentena extraídos dos scripts do 220 e registrados no contrato de cada robô, com unidade e teste de fronteira. Se o legado não fornecer valor inequívoco, pedir decisão ao usuário antes de implementar o parâmetro.
- Output: entradas em `routes/console.php` (`walle:robo-contingencias`, `walle:robo-sentinela`, `walle:robo-cleantax`, `walle:robo-tarefas`, cada uma com `->when(fn ($robo) => $robo->ligado())`), blocos em `config/walle.php` com `WALLE_CONTINGENCIAS_ATIVO`, `WALLE_SENTINELA_ATIVO`, `WALLE_CLEANTAX_ATIVO`, `WALLE_TAREFAS_ATIVO` (todos `false`), supervisores na conexão `robos` em `config/horizon.php` e as variáveis no `.env.example`
- Testes críticos:
  - [x] `tests/Feature/AgendadorTest.php`: com cada `WALLE_*_ATIVO=true`, o evento do robô aparece como devido no agendador
  - [x] com o robô desligado (padrão), o evento não dispara o Job
- Decisões do usuário de 27/10/2026: frequências iguais ao intervalo entre ciclos dos daemons do 220 (contingências 10 s, Sentinela 10 min, CleanTax 30 s) e Task Scheduler a cada minuto (o crontab real do 220 não é versionado); sem quarentena nem limite de falhas, porque o 220 não os tem; token do CleanTax só do `.env`. O `schedule:work` precisa estar rodando para os intervalos de segundos.

## Sprint 3 — Todas as telas do newsuporte que faltam abrem no walle, no padrão walle-ui, com os dados do banco `walle` (`docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php tests/Feature/Relatorios` passa)

### Fase 6 — Telas · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Relatorios tests/Feature/Contingencias/PainelRoboContingenciasTest.php tests/Feature/Sentinela/PainelSentinelaTest.php tests/Feature/CleanTax/PainelCleanTaxTest.php tests/Feature/Ferramentas/PainelFerramentasTest.php tests/Feature/CrontabTest.php`
> Dependências: Fase 2 (dados); novas telas com tabela dependem também da task 6.0. As telas leem as tabelas e não dependem dos robôs estarem ligados.
> Fora desta fase: a task 6.4 (painel da Classificação Mercadológica) foi para a seção manual no fim do plano.
> Paralelismo: depois de 6.0 para telas com tabela, 6.1, 6.2, 6.3, 6.5, 6.6 e 6.7 rodam em paralelo (cada uma cria o seu componente Livewire, as suas views e o seu teste; rotas e menu ficam para a Fase 7)

#### Task 6.0 — Componente reutilizável de tabela (pré-requisito das novas telas com tabela)
- Agent: Frontend Livewire (walle-ui)
- Input: tabelas existentes, tokens do tema e regras de acessibilidade do walle-ui.
- Output: `resources/views/components/ui/tabela.blade.php` e subcomponentes necessários, em tarefa própria antes das telas dependentes. Preservar telas atuais até tarefa explícita de migração; tabelas de gráficos continuam usando `<x-grafico.tabela>`.
- Critérios: tema claro/escuro, cabeçalhos com `scope`, números alinhados, rolagem horizontal e compatibilidade com `wire:key`. Validar renderização e comportamento próprios, sem testes que apenas repitam classes CSS.

#### Task 6.1 — Relatórios (substitui `consultorias/relatorios`: Clientes, Contingência NFC-e, Motivos)
- Agent: Frontend Livewire (walle-ui)
- Input: `history_contingencia` (walle) e `c_cliente` (conexão `help`)
- Output: `app/Livewire/Relatorios.php` com `ABAS` `clientes`, `contingencia`, `motivos` e `resources/views/livewire/relatorios/{clientes,contingencia,motivos}.blade.php`; Motivos agrupa por semana (segunda a domingo, como `get.motivos.contingencias.php`) com `<x-grafico.barras>` e a tabela dos mesmos números
- Testes críticos:
  - [x] `tests/Feature/Relatorios/RelatoriosTest.php`: com contingências semeadas, a aba Motivos mostra a mesma contagem por motivo que o 220 mostra para a mesma semana
  - [x] período sem dados mostra `<x-ui.vazio>` e aba inválida na URL volta para `clientes`
- Desvios conhecidos da 6.1: o 220 calcula o período mas não o aplica no SQL dos motivos (o walle aplica em `data_clique`); a aba Clientes não mostra cidade/UF (sem `c_cidade`/`c_estado` nas imitações do Help Desk); ficaram fora o modal por motivo, a exportação Excel/PDF, "Reprocessar todos" e a busca livre. Rota e menu na task 7.1.

#### Task 6.2 — Painel do Contingência BOT (substitui `contingency.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: models da task 1.2
- Output: `app/Livewire/Contingencias/Robo.php` e `resources/views/livewire/contingencias/robo.blade.php` (KPIs, clientes com última coleta e erro), com a aba `robo` acrescentada em `App\Livewire\Contingencias::ABAS`
- Testes críticos:
  - [x] `tests/Feature/Contingencias/PainelRoboContingenciasTest.php`: a aba lista os clientes de `painel_contingency_crawler` com a última coleta
  - [x] cliente com erro aparece com `<x-ui.etiqueta cor="erro">` e o robô desligado mostra o aviso de desligado

#### Task 6.3 — Painel do Sentinela (substitui `sentinela.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: model da task 1.1
- Output: `app/Livewire/Sentinela.php` (filtro `#[Url] $cliente`, como o `?id=` do 220) e `resources/views/livewire/sentinela.blade.php`
- Testes críticos:
  - [x] `tests/Feature/Sentinela/PainelSentinelaTest.php`: a tela mostra cada cliente com o estado coletado e filtra pelo cliente da URL
  - [x] cliente inexistente na URL mostra o vazio, sem erro
- Fora da 6.3 (o 220 tinha e a task não pediu): busca, ordenação, CSV e modal de detalhes. Rota e menu na task 7.1.

#### Task 6.5 — Painel do CleanTax (substitui `cleantax.dash.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: model da task 1.4
- Output: `app/Livewire/CleanTax.php` e `resources/views/livewire/clean-tax.blade.php`
- Testes críticos:
  - [x] `tests/Feature/CleanTax/PainelCleanTaxTest.php`: lista as execuções da iteração com início, fim e resultado
  - [x] sem nenhuma execução mostra o vazio
- Fora da 6.5 (o 220 tinha e a task não pediu): KPIs por status, HTTP 200 e tempo médio, busca, filtros e modal de detalhes. A tabela guarda a última execução de cada cliente (sem histórico). Rota e menu na task 7.1.

#### Task 6.6 — Ferramentas: Task Scheduler e relatório (substitui `programacao/tools` e `programacao/tools/reports`)
- Agent: Frontend Livewire (walle-ui)
- Input: models da task 1.5
- Output: `app/Livewire/Ferramentas.php` com `ABAS` `querys` e `relatorio` (criar, editar, ativar/desativar queries — só admin com `ConfirmaSenha`, métodos em `ACOES_COM_SENHA`) e `resources/views/livewire/ferramentas/{querys,relatorio}.blade.php`
- Testes críticos:
  - [x] `tests/Feature/Ferramentas/PainelFerramentasTest.php`: admin cria uma query depois de confirmar a senha e ela aparece na lista com `Auditoria`
  - [x] usuário que não é admin recebe `403` ao salvar
- Entregue na T-0021 (a T-0019 foi substituída: o branch dela ficou atrás do `main`). Decisões da 6.6: query nova nasce desativada (o 220 usa o default do banco); editar pede senha, porque carrega o SQL no formulário; a lista não mostra o SQL; a auditoria guarda só o id da query. A query é guardada em base64, como no 220 e no `RoboTarefas` (limite de 2802 bytes de SQL puro, pelo tamanho da coluna) e o campo usa `<x-ui.area-texto>` para preservar quebras de linha. A codificação ainda está duplicada entre `Ferramentas` e `RoboTarefas`: mover para o model é tarefa futura. Fora da 6.6: coluna "Finalizado" e exclusão do 220; o usuário gravado na query é o de quem criou (o 220 troca a cada edição). Execução nos clientes é da 8.3; rota e menu, da 7.1.

#### Task 6.7 — Crontab (substitui `crontab/index.php`, Cron Control Center)
- Agent: Frontend Livewire (walle-ui)
- Input: eventos do agendador (`Illuminate\Console\Scheduling\Schedule`)
- Output: `app/Livewire/Crontab.php` e `resources/views/livewire/crontab.blade.php` com cada rotina do `routes/console.php` (nome, frequência, próxima execução, ligada/desligada)
- Testes críticos:
  - [x] `tests/Feature/CrontabTest.php`: as rotinas `walle:robo-autorizadas` e `walle:robo-sactus` aparecem com a frequência certa
  - [x] robô desligado no `.env` aparece como "desligado"
- Na 6.7, a "próxima execução" das rotinas de segundos (contingências e CleanTax) mostra o próximo minuto, não o próximo disparo de 10 s ou 30 s (o Laravel guarda a expressão `* * * * *` e o intervalo em `repeatSeconds`). Rota e menu na task 7.1.

### Fase 7 — Rotas, menu e saúde das telas novas · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php && docker exec walle-app php artisan route:list --except-vendor`
> Dependências: Fase 5 e Fase 6
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 7.1 — Registrar as telas novas
- Agent: Backend Laravel — infraestrutura
- Input: componentes das tasks 6.1 a 6.7 (sem a 6.4) e robôs da Fase 5
- Output: rotas `relatorios`, `sentinela`, `clean-tax`, `ferramentas` e `crontab` em `routes/web.php` (grupo `auth`), itens com `<x-ui.menu-item>` em `resources/views/layouts/app.blade.php` (Programação só para admin) e um item por robô novo em `App\Livewire\Saude`
- Testes críticos:
  - [x] `tests/Feature/TelasTest.php`: cada rota nova abre (`200`) para usuário logado e a Saúde mostra os robôs novos como "Desligado neste servidor"
  - [x] visitante é redirecionado para `entrar`; usuário que não é admin não vê o menu Programação
- Na 7.1, as rotas `ferramentas` e `crontab` abrem para qualquer usuário logado (conforme o teste crítico); as ações sensíveis ficam protegidas dentro da tela (admin + senha) e só o menu Programação é restrito a admin. A Saúde mostra os robôs novos só como ligado/desligado, sem conferir o último ciclo.

## Sprint 4 — As ações do newsuporte mantidas no escopo existem no walle (excluídas escritas no Help Desk), só para admin, com senha confirmada (`ConfirmaSenha`) e registro em `Auditoria` (`docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias tests/Feature/Ferramentas tests/Feature/Sactus tests/Feature/Conversor tests/Feature/InformacoesTecnicas` passa)

### Fase 8 — Ações nos servidores dos clientes e no banco `walle` · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Contingencias/AcoesContingenciasTest.php tests/Feature/Contingencias/ListaDeClientesAcoesTest.php tests/Feature/Ferramentas/ExecucaoDeQueryTest.php tests/Feature/InformacoesTecnicas`
> Dependências: Fase 7
> Paralelismo: 8.1, 8.2, 8.3 e 8.4 rodam em paralelo (8.1 só mexe em `Contingencias\DoCliente`, 8.2 só em `Livewire\Contingencias` e na lista, 8.3 só em `app/Ferramentas` e `Livewire\Ferramentas`, 8.4 cria o seu módulo)

#### Task 8.1 — Ações de contingência do cliente (substitui `findSale`, `deleteContAdm`, `atualizaAliquotas`, `reprocessCont` e `getCrontab` do `contingencias.js`)
- Agent: Backend Laravel — robôs
- Input: `ConectorDeCliente`, tela `Contingencias\DoCliente`
- Output: `app/Contingencias/AcoesContingencias.php` com `reenviarAoPdv(int $clienteId, array $cupons): ResultadoAcao`, `removerDaListagem(int $clienteId, array $chaves): int`, `atualizarAliquotas(int $clienteId): ResultadoAcao`, `reprocessar(int $clienteId): ResultadoAcao` e `crontabDoCliente(int $clienteId): string`; botões em `app/Livewire/Contingencias/DoCliente.php` (métodos em `ACOES_COM_SENHA`)
- Testes críticos:
  - [x] `tests/Feature/Contingencias/AcoesContingenciasTest.php`: admin com senha confirmada reenvia 2 cupons ao PDV (o SSH falso recebe os 2 comandos `funcid="858"` do 220, em vez do `UPDATE contingencias_versatus`) e a ação fica em `Auditoria`
  - [x] sem senha confirmada ou sem ser admin nada é enviado; falha de conexão mostra o erro sem alterar a tela
- Recorte da 8.1 (contrato do `walle-arquiteto`, decisões do usuário de 27/10/2026): implementadas `reenviarAoPdv` e `reprocessar` (XML de comando gravado por SSH no contêiner Sactus do cliente, com a credencial DOCKER do cadastro lida ao vivo da conexão `help`; sem host nem senha fixos). `removerDaListagem`, `atualizarAliquotas` e `crontabDoCliente` ficaram fora: no 220 a remoção está comentada e só gravava no Help Desk, as alíquotas são uma função vazia e o crontab é manutenção do servidor 220, sem cliente. A flag `reprocessar` de `contingencias_versatus` não foi gravada (a tabela não existe no walle e nenhum código a lê). Lacuna de infra: `tests/Migrations` não tem a tabela `pdv` do Sactus (o teste cria uma temporária).

#### Task 8.2 — Remover cliente da lista de contingências (substitui `delete.php`)
- Agent: Backend Laravel — dados
- Input: `contingencias_versatus_venda` (walle) e tela `Contingencias`
- Output: `app/Contingencias/RemocaoDeCliente.php` (`remover(int $clienteId): int` — linhas apagadas de `contingencias_versatus_venda` e itens, numa transação) e o botão na lista de `app/Livewire/Contingencias.php` (admin + `ConfirmaSenha`)
- Testes críticos:
  - [x] `tests/Feature/Contingencias/ListaDeClientesAcoesTest.php`: remover um cliente apaga só as vendas e itens dele e registra `Auditoria`
  - [x] id inválido (zero, negativo ou inexistente) não apaga nada e avisa
- Na 8.2, o 220 (`delete.php`) apagava só as vendas, com `foreign_key_checks=0`, e deixava os itens órfãos; o walle apaga vendas e itens numa transação. Id inválido devolve 0 e a tela mostra o aviso de erro (sem exceção). Não há models dessas tabelas: a remoção usa `DB::table`.

#### Task 8.3 — Execução de query e ferramentas de debug (substitui `execute.query.php`, `execute.query.available.php` e `debugs.tools.php`)
- Agent: Backend Laravel — segurança
- Input: `ConectorDeCliente` e tela `Ferramentas` (task 6.6)
- Output: `app/Ferramentas/ExecucaoDeQuery.php` (`executar(int $clienteId, string $sql): ResultadoQuery`, qualquer SQL manual, inclusive `SELECT`, somente admin com confirmação válida na sessão) e a aba `executar` em `App\Livewire\Ferramentas`
- Testes críticos:
  - [x] `tests/Feature/Ferramentas/ExecucaoDeQueryTest.php`: admin com confirmação válida executa `SELECT` no cliente falso e recebe as linhas e registra `Auditoria` com o SQL (sem valores sensíveis)
  - [x] `SELECT`/`UPDATE`/`DELETE` sem ser admin ou sem confirmação válida é recusado antes de conectar no cliente; confirmação expirada pede senha novamente
- Recorte da 8.3 (decisões do usuário de 27/10/2026): portada só a execução manual de query (`execute.query.php`), com a regra do projeto. Ficam fora, por pedido explícito futuro: `execute.query.available.php` (senha por padrão fixo, IP fixo e credenciais vazadas no erro) e as ferramentas de debug de `debugs.tools.php` (o tipo 3 escreve em `venda_sum_produto` no banco do cliente). A auditoria guarda o SQL com literais trocados por `?` (máximo de 500 caracteres) e as recusas sem SQL. Limites: uma instrução por chamada, 1000 linhas e 30 s. Riscos conhecidos: a regra de validade da senha está duplicada no serviço (extrair em tarefa futura); `WITH`, `TABLE` e `SELECT ... FOR UPDATE` são tratados como escrita e não devolvem linhas; o resultado fica inteiro no buffer do PDO antes do corte; a instrução roda no banco do Sactus resolvido pelo `ConectorDeCliente`.

#### Task 8.4 — Informações Técnicas do cliente (substitui `consultorias/config`: Configurações do Servidor e de PDV, `config/api/import.php`, `sets.php`, `gets.php`)
- Agent: Frontend Livewire (walle-ui)
- Input: tabela `informacao_tecnica_painel_contingencias` (task 1.2) e `c_cliente` (conexão `help`)
- Output: `app/InformacoesTecnicas/ConfiguracaoTecnica.php` (`salvar(int $clienteId, array $dados): void`, `importarDoHelpDesk(int $clienteId): void`) e `app/Livewire/InformacoesTecnicas.php` com `resources/views/livewire/informacoes-tecnicas.blade.php` (formulário com `<x-ui.campo>`)
- Testes críticos:
  - [x] `tests/Feature/InformacoesTecnicas/InformacoesTecnicasTest.php`: salvar as configurações de servidor e PDV grava a linha do cliente e reabrir mostra os mesmos valores
  - [x] campo obrigatório vazio ou porta fora do intervalo mostra o erro de validação e não grava
- Decisões da 8.4 (usuário, 27/10/2026): salvar e importar exigem admin e `ConfirmaSenha`, porque a tabela guarda senhas; senhas nunca vão à tela, a log nem à auditoria (campo vazio mantém a atual, como o "OCULTA" do 220); só as colunas de uma lista fixa são gravadas (o `sets.php` do 220 aceitava nomes de coluna do cliente); a importação lê o Help Desk (SELECT) e grava só no banco local, preservando `tips_*` e `emissao_cupons` (o `REPLACE` do 220 recriava a linha) e sem a senha SSH literal do `atualizar.php`. Validação nova (o 220 não validava): obrigatórios e portas de 1 a 65535; campos do Docker só quando `tips_server` = DO. Rota e menu na task 11.1.

### Fase 10 — Ferramentas auxiliares · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Conversor`
> Dependências: Fase 7
> Paralelismo: nenhum (uma task: 10.1)

#### Task 10.1 — Conversor de certificado (substitui `menu/conversor.php`)
- Agent: Backend Laravel — segurança
- Input: nenhuma além da Fase 7
- Output: `app/Conversor/ConversorCertificado.php` (`converter(string $conteudoPfx, string $senha): CertificadoConvertido`, tudo em memória, nada gravado em disco nem em log) e interface de conversão com recebimento e entrega inteiramente em memória, sem upload temporário. Definir o transporte antes de implementar, garantindo que servidor web, PHP e interface não gravem o corpo em disco; não usar upload temporário convencional do Livewire
- Testes críticos:
  - [x] `tests/Feature/Conversor/ConversorCertificadoTest.php`: um `.pfx` de teste com a senha certa vira PEM com certificado e chave, e `Auditoria` registra a conversão sem a senha
  - [x] senha errada ou conteúdo que não é `.pfx` mostra o erro; sucesso e falha não criam arquivos, inclusive temporários de upload, nem persistem senha/PFX/PEM/chave em sessão, cache, fila ou log
- Entregue na T-0035. Decisão do usuário de 28/10/2026 sobre o transporte: o navegador lê o `.pfx` com `FileReader` e chama a ação do Livewire com o conteúdo em base64 e a senha como argumentos (sem `<form>`, `wire:model` nem `WithFileUploads`); o PEM volta só no retorno da ação, é exibido e o botão de baixar gera o arquivo no navegador (Blob). Nenhuma propriedade do componente guarda segredo; `CertificadoConvertido` recusa serialização. Admin com `ConfirmaSenha`: a ação pendente é `liberar`, sem argumentos, e o navegador reenvia o arquivo que ficou nele. Limite de 10 KB para o `.pfx` e de 128 caracteres para a senha (`SENHA_MAXIMA`), no navegador e no servidor (corpo medido no pior caso: 15.116 bytes, teste exige menos de 15.360), para o corpo caber no buffer do PHP-FPM e do nginx. Reforço no nginx, aplicado pelo usuário porque nenhum agente edita `docker/nginx/` (o buffer padrão do nginx é 8 KB e um `.pfx` comum estouraria): `client_body_buffer_size 32k;` em `server` e `fastcgi_max_temp_file_size 0;` em `location ~ ^/index\.php(/|$)`; o `walle-web` precisa ser reiniciado. Auditoria `conversor.converter` guarda resultado, titular (CN) e validade, nunca senha, PFX ou PEM. Risco residual: um admin que burle o navegador e envie mais de 16 KB faz o PHP usar temporário antes da recusa; opção futura é montar o `/tmp` do `walle-app` em tmpfs. Rota e menu, na 11.1.

#### Task 10.2 — Updates (substitui `programacao/updates`, árvore de `sistema/app`) — CANCELADA
- Decisão do usuário de 28/10/2026: o `programacao/updates` do 220 não vem para o walle. Nenhuma tela, rota, configuração (`walle.updates.raiz`) nem montagem do `sistema/app` será criada.

### Fase 11 — Rotas e menu das telas do Sprint 4 · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/TelasTest.php`
> Dependências: Fase 8 e Fase 10
> Paralelismo: nenhum (uma task: arquivos compartilhados)

#### Task 11.1 — Registrar Informações Técnicas e Conversor
- Agent: Backend Laravel — infraestrutura
- Input: componentes das tasks 8.4 e 10.1
- Output: rotas `informacoes-tecnicas` e `conversor` em `routes/web.php` e itens no menu Programação de `resources/views/layouts/app.blade.php`
- Testes críticos:
  - [x] `tests/Feature/TelasTest.php`: as duas rotas abrem para admin logado
  - [x] usuário que não é admin recebe `403` nas duas

## Sprint 5 — Com os mesmos dados, cada módulo migrado devolve o mesmo resultado que o newsuporte e os robôs do 220 podem ser desligados, exceto a Classificação Mercadológica, que segue no 220 até a seção manual no fim do plano (`docker exec walle-app ./vendor/bin/pest tests/Feature/Paridade` passa)

### Fase 12 — Paridade com o newsuporte · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/Paridade`
> Dependências: Sprints 1 a 4
> Paralelismo: 12.1, 12.2 e 12.4 rodam em paralelo (cada uma cria só o seu teste e as suas amostras em `tests/Fixtures/legado/<modulo>/`)

#### Task 12.1 — Paridade dos robôs de coleta (Contingência BOT, Sentinela, CleanTax)
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
  - [x] Clientes, Contingência NFC-e e Motivos mostram os mesmos totais do 220 para o mesmo período
  - [x] semana sem contingências mostra zero nos dois

#### Task 12.4 — Paridade das ações de contingência e ferramentas
- Agent: QA — paridade com o newsuporte
- Input: comandos que o 220 enviou aos clientes (reenviar ao PDV, alíquotas, queries agendadas) capturados no `testes220`
- Output: `tests/Feature/Paridade/AcoesParidadeTest.php` e amostras em `tests/Fixtures/legado/acoes/`
- Testes críticos:
  - [ ] para cada ação, o conector falso do walle recebe exatamente o mesmo SQL ou comando que o 220 enviou
  - [ ] ação recusada no walle (sem senha, sem admin) não envia nada ao cliente

## Segurança — o Help Desk é só consultado, ao vivo (`docker exec walle-app ./vendor/bin/pest tests/Feature/HelpDesk` passa)

### Fase 13 — Help Desk somente leitura · conclui com `docker exec walle-app ./vendor/bin/pest tests/Feature/HelpDesk`
> Origem: pedido do usuário em `.claude/commands/PLANSNOVO.md.txt` (auditoria da integração com o 192.168.10.254).
> Decisão de 25/10/2026: o walle não mantém cópia (espelho) das tabelas do Help Desk. Consulta ao vivo, pela conexão `help`, com o usuário `walle` do .254, que só tem `SELECT` no banco `suporte`. As tasks do espelho (13.4 tabelas locais, 13.5 sincronização e edições, 13.6 Job, 13.7 configuração e agenda, 13.9 tela de edição) foram canceladas e o código escrito delas foi removido.
> Dependências: nenhuma. Paralelismo: nenhum (as tasks mexem nos mesmos arquivos de `app/Support/HelpDesk`).

#### Task 13.1 — Trava de leitura na conexão `help`
- Agent: Backend Laravel — segurança
- Output: `app/Support/HelpDesk/TravaHelpDeskProducao.php` (registrada em toda conexão pelo `AppServiceProvider`), `ClassificadorSql.php`, `EscritaNoHelpDeskDeProducao.php`, `PermissoesHelpDesk.php`, `ResultadoPermissoes.php` e `app/Models/Concerns/SomenteLeitura.php`
- Testes críticos:
  - [x] `tests/Feature/HelpDesk/ProtecaoHelpDeskProducaoTest.php`: no host de produção, toda instrução que não é leitura é barrada antes de conectar, inclusive em conexão recriada ou dinâmica; a leitura passa
  - [x] `tests/Feature/HelpDesk/ConexaoHelpSomenteLeituraTest.php`: fora dos testes, a conexão `help` só lê em qualquer host; `Usuario->save()` e `delete()` são barrados; cada tentativa vira uma auditoria `help_desk.escrita_barrada`, sem SQL nem valores
  - [x] `tests/Feature/HelpDesk/PermissoesHelpDeskTest.php`: o `SHOW GRANTS` é interpretado sem guardar a linha bruta nem o hash da senha
  - [x] `tests/Feature/HelpDesk/SemAcessoDiretoAoPdoTest.php`: nada em `app/` usa o PDO direto, fora do conector de cliente

#### Task 13.1b — Reforços da trava apontados na revisão
- Agent: Backend Laravel — segurança
- Input: revisão do `walle-arquiteto` de 25/10/2026
- Output: ajustes em `app/Support/HelpDesk/` e nos testes de `tests/Feature/HelpDesk/`
- Testes críticos:
  - [x] nos testes, a trava pelo nome só fica desligada quando o banco da `help` casa com `^walle_testes(_t[0-9]+)?_help$`
  - [x] a auditoria da escrita barrada não se perde quando a transação do walle é desfeita
  - [x] `WITH ... GRANT OPTION` com outras palavras no meio conta como escrita; `TravaHelpDeskProducao::conferir()` é removido ou ganha teste
  - [x] a varredura de PDO direto cobre também `routes/`, `database/`, `config/` e `bootstrap/`
  - [x] a regex de banco de teste do `tests/TestCase.php` usa `\z` em vez de `$`

#### Task 13.1c — Sugestões da revisão da 13.1b
- Agent: Backend Laravel — segurança
- Input: sugestões do `walle-arquiteto` na revisão do PR #13; pedido do usuário de 26/10/2026
- Output: testes em `tests/Feature/HelpDesk/`
- Testes críticos:
  - [x] nos testes, conexão da lista com banco `walle_testes_help` e host de produção continua barrada (o host vem antes da isenção)
  - [x] no `config/database.php` real, a conexão `auditoria` é idêntica à `mysql`
  - [x] a varredura de PDO direto detecta também `new PDO(`, `new Pdo\Mysql(` e `::connect(`
  - [x] a asserção de banco do `BancoDeTesteDoHelpDeskTest` usa `\z`

#### Task 13.2 e 13.3 — Imitação do Help Desk num banco de teste separado
- Agent: Backend Laravel — dados (13.2), infraestrutura (13.3) e segurança (13.3b)
- Output: `tests/Migrations/HelpDesk/` (imitação de `a_user`, `c_cliente`, `informacao_tecnica_dataserver`...) aplicada no banco `<DB_DATABASE>_help`; `tests/TestCase.php` aponta a conexão `help` para ele e recusa banco fora de `walle_testes` ou `walle_testes_tNN`
- Testes críticos:
  - [x] `tests/Feature/HelpDesk/BancoDeTesteDoHelpDeskTest.php`: nos testes a `help` usa `<banco>_help` e nunca um host de produção; banco fora do padrão é recusado antes de criar qualquer banco

#### Task 13.8 — Regras do projeto
- Agent: Harness
- Output: `.ai/guidelines/walle.md`, `CLAUDE.md` e `AGENTS.md` (Help Desk somente leitura por nome e por host, sempre consultado ao vivo, tentativas barradas na auditoria) e as duas cópias da skill `portar-tabela-220` (tabela do Help Desk não vira migration)
- Testes críticos:
  - [x] cópias sincronizadas e teste da política do harness: 327 casos

#### Task 13.10 — Usuário `walle` só com `SELECT` e fim da API do Help
- Agent: sessão principal; o usuário `walle` foi criado no 192.168.10.254 pelo usuário em 25/10/2026
- Output: `config/database.php` com `HELP_DB_USERNAME=walle` e a senha só do `.env` (`HELP_DB_SENHA_RESERVA`); removidos `app/Support/HelpDesk/SenhaHelpDesk.php` (que buscava a senha do root na API do Help) e o bloco `services.help`; a Saúde mostra o usuário da conexão
- Testes críticos:
  - [x] conferência real no .254 em 25/10/2026, só com leitura: a conexão chega como `walle@192.168.99.237` e `PermissoesHelpDesk::verificar()` dá somente leitura, sem privilégio de escrita
  - [x] `tests/Feature/HelpDesk/ConexaoHelpSomenteLeituraTest.php`: nada em `app/` nem em `config/` pede a senha à API do Help
  - [x] `tests/Feature/SaudeTest.php`: a Saúde mostra o resultado de `PermissoesHelpDesk` (somente leitura, privilégios de escrita ou erro ao conferir)

#### Pendências fora do walle
- [ ] Restringir o `root@%` do 192.168.10.254 e trocar a senha dele (a API do Help ainda a entrega).
- [ ] Apagar os logs do 220 que guardam a senha do root em texto puro (`response.log` e `login.class.php`).

## Manual, fora da automação — Classificação Mercadológica (por último)

> Decisão de 26/10/2026: o robô da Classificação Mercadológica grava (`INSERT`, `UPDATE` e `DELETE`) nos bancos dos clientes, então fica fora de todo o fluxo automático (orquestrador, comandos, agenda, paridade automática). Só começa depois de todo o resto do plano, com pedido explícito do usuário em cada passo; nenhum agente inicia estas tasks por conta própria. As tabelas da task 1.3 já existem e continuam no importador. Ordem: 3.3, 6.4 e por fim 7.2.

#### Task 3.3 — Classificação Mercadológica (substitui `mercadological.worker.php` e `set.mercadological.worker.php`)
- Agent: Backend Laravel — robôs, só com pedido explícito do usuário
- Input: models da task 1.3
- Output: `app/Mercadologica/RoboMercadologica.php` (ciclo sequencial, um cliente por vez, só clientes com `executar_bot_class_mercadologica = 'S'`, quarentena após o limite de falhas consecutivas do 220; registrar limite e duração exatos no contrato antes de implementar), `app/Mercadologica/SincronizacaoMercadologica.php` (`sincronizar(int $clienteId): ResultadoSincronizacao`) e `app/Jobs/RodarCicloMercadologica.php`
- Contrato obrigatório antes de implementar (revisão de 26/10/2026):
  - No 220, a "primeira execução" depende de `data_exec_bot_class_mercadologica` vazia no `c_cliente` do Help Desk e roda `UPDATE produto SET fk_p_grupo=1, fk_p_categoria=1, fk_p_familia=1` no banco do cliente (`set.mercadological.worker.php`); depois do sucesso, o 220 grava essa data no Help Desk. O walle não grava no Help Desk: a data da última execução fica numa tabela do walle e **só ela** decide a primeira execução. Sem isso, cada ciclo viraria "primeira execução" e zeraria a classificação dos produtos de novo.
  - O 220 abre atendimento no Help Desk (`health.mercadological.worker.php`, `INSERT INTO s_atendimento`); no walle isso é proibido e não é portado.
  - As credenciais da base de origem que o script guarda em constantes vão para o `.env`.
  - A paridade (amostras do 220 e cliente falso) é definida no contrato desta task, já que a 12.1 não cobre a Mercadológica.
- Testes críticos:
  - [ ] `tests/Feature/Mercadologica/RoboMercadologicaTest.php`: cliente habilitado sem cadastro técnico aparece como aviso (não some em silêncio, correção do 220) e o cliente com cadastro é sincronizado e logado
  - [ ] cliente atinge o limite documentado de falhas, fica de fora pela duração documentada da quarentena e volta depois; testar também o ciclo anterior ao limite
  - [ ] o reset da classificação (grupo, categoria e família = 1) roda no máximo uma vez por cliente, decidido pela data guardada no walle, e nenhuma escrita vai ao Help Desk

#### Task 6.4 — Painel da Classificação Mercadológica (substitui `mercadological.dash.php`)
- Agent: Frontend Livewire (walle-ui), só com pedido explícito do usuário
- Input: models da task 1.3
- Output: `app/Livewire/Mercadologica.php` e `resources/views/livewire/mercadologica.blade.php` (KPIs, clientes, histórico do cliente aberto, "sem sinal" pela régua de ciclos do 220)
- Testes críticos:
  - [ ] `tests/Feature/Mercadologica/PainelMercadologicaTest.php`: os KPIs batem com a contagem das linhas semeadas e o histórico abre o cliente certo
  - [ ] cliente que passou da régua de ciclos aparece como "sem sinal"

#### Task 7.2 — Agenda, rota, menu e Saúde da Classificação Mercadológica
- Agent: Backend Laravel — infraestrutura, só com pedido explícito do usuário, depois da 3.3 e da 6.4
- Input: Job da task 3.3 e componente da task 6.4
- Output: `walle:robo-mercadologica` em `routes/console.php` com `->when(fn ($robo) => $robo->ligado())`; bloco em `config/walle.php` com `WALLE_MERCADOLOGICA_ATIVO` (padrão `false`) e a variável no `.env.example`; supervisor na conexão `robos` em `config/horizon.php`; rota `mercadologica` em `routes/web.php`, item no menu de `resources/views/layouts/app.blade.php` e item do robô em `App\Livewire\Saude`
- Testes críticos:
  - [ ] `tests/Feature/AgendadorTest.php`: com `WALLE_MERCADOLOGICA_ATIVO=true` o evento aparece como devido; desligado (padrão), não dispara o Job
  - [ ] `tests/Feature/TelasTest.php`: a rota `mercadologica` abre (`200`) para usuário logado e a Saúde mostra o robô como "Desligado neste servidor"
