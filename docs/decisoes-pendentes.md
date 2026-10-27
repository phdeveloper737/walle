# Decisões pendentes — walle

> Arquivo vivo mantido pelo orquestrador. Cada item tem contexto, opções numeradas e a recomendação.
> Última atualização: 27/10/2026, durante a execução do `/executar-plano` (T-0013 a T-0027).
> Regra do usuário (27/10/2026): nas decisões de produto/escopo, seguir a recomendação sem perguntar; este arquivo reúne o que depende do usuário ou de manutenção do harness, para análise em outro chat.

## A. Harness e processo (precisam de quem pode editar hooks, políticas e scripts)

### A1. O gate não grava a saída do Pest
- **Sintoma:** o Pest falha de forma intermitente na primeira execução do gate (de 2 a 8 testes; uma vez 154) e passa na segunda, sem mudança no código. Os nomes dos testes que falham nunca aparecem, porque `gate.ps1` só grava o código de saída de cada etapa.
- **Hipótese mais provável:** colisão com o hook de Stop do agente implementador, que roda a suíte inteira no mesmo banco `walle_testes` enquanto o verificador roda o gate. Reforço: quando o gate rodou bem depois do fim do implementador, passou de primeira (T-0023, T-0025).
- **Opções:**
  1. `gate.ps1` grava a saída do Pest (ou só a lista de testes que falharam) em `.walle/gate/pest-ultimo.txt`. Recomendada.
  2. O orquestrador só pede o gate depois que a notificação de fim do implementador chega (processo; já adotado como prática, mas sem barreira técnica).
  3. O hook de Stop do implementador usa um banco próprio (`walle_testes_stop`) em vez de `walle_testes`.
- **Recomendação:** 1 e 3 juntas (tarefa do `walle-hooks`, mas `gate.ps1` está fora do alcance dele: edição manual sua).

### A2. Não há como atualizar um branch atrasado em relação ao `main`
- **Sintoma:** a T-0019 (task 6.6) ficou atrás do `main` e sem o componente `<x-ui.area-texto>` (T-0020). `tarefa.ps1` não tem ação para isso e os agentes só têm `git status` e `git diff`. Foi preciso refazer a task do zero (T-0021) e deixar a T-0019 bloqueada como "substituída".
- **Opções:**
  1. Nova ação `-Acao atualizar` no `tarefa.ps1` (`git merge origin/main`, com recusa se houver conflito). Recomendada.
  2. Regra de processo: tarefa que depende de outra aberta só abre depois da integração da dependência (já adotado).
- **Recomendação:** 1.

### A3. Duas permissões de teste que os agentes não têm
- A tabela `pdv` do Sactus não existe na imitação `tests/Migrations/0001_01_01_000002_create_tabelas_sactus_de_teste.php`; o `walle-robos` não pode editá-la (o teste da T-0024 usa uma tabela temporária, que o arquiteto aceitou). Dono sugerido: `walle-infra` ou `walle-dados`. Recomendação: tarefa curta para criar a migration de imitação.
- A regra de validade da confirmação de senha (`walle.senha_confirmada_em` + `walle.confirmar_senha_minutos`) está duplicada em `ExecucaoDeQuery` e no trait `ConfirmaSenha`, porque a política barrou `app/Support/ConfirmacaoDeSenha.php`. Recomendação: extrair para uma classe única numa tarefa do `walle-seguranca`.

### A4. Gate em memória baixa
- O Claude Code encerrou o gate da T-0027 por "memória do sistema criticamente baixa". Não desativei `CLAUDE_CODE_DISABLE_BG_SHELL_PRESSURE_REAP`. Recomendação: liberar memória (ou reduzir contêineres) antes de rodar o gate; a suíte tem mais de 500 testes e leva de 150 a 360 s.

## B. Produto e segurança (pendências deixadas pelas revisões)

### B1. Rotas `ferramentas` e `crontab` abrem para qualquer usuário logado
- A task 7.1 pedia "cada rota nova abre (200) para usuário logado". O menu Programação é só para admin, mas quem digitar a URL vê a lista de queries (sem o SQL), o relatório de execuções (com mensagens de erro do MySQL do cliente) e a agenda.
- **Opções:** (1) `->middleware('can:admin')` nas duas rotas, como já está em `conhecimento`; teste passa a ser "403 para não admin" (a task 11.1 já espera 403 para não admin nas três rotas dela). (2) Manter como está.
- **Recomendação:** 1, em tarefa pequena do `walle-infra`.

### B2. Ferramentas de debug e `execute.query.available.php` do 220 (task 8.3)
- Não foram portadas: `execute.query.available.php` usa senha por padrão fixo, IP fixo e vazamento de credencial; `debugs.tools.php` tipo 3 grava em `venda_sum_produto` no banco do cliente.
- **Opções:** (1) não portar nunca; (2) portar só os debugs 1 e 2 (leitura) como ações de admin com senha e auditoria; (3) portar também o 3 com a mesma regra da Classificação Mercadológica (manual).
- **Recomendação:** 2, só quando você pedir.

### B3. Limites conhecidos da execução manual de SQL (task 8.3)
- `WITH`, `TABLE` e `SELECT ... FOR UPDATE` são tratados como escrita e não devolvem linhas; o resultado inteiro fica no buffer do PDO antes do corte de 1000 linhas; a auditoria é gravada depois da execução (se o PHP morrer no meio, a instrução já chegou ao cliente); o mascaramento da auditoria deixa passar `0b1010`, `NO_BACKSLASH_ESCAPES` e `/*M!`; a instrução roda no banco do Sactus resolvido pelo conector (no 220, no banco com o nome do usuário MySQL). Falta teste de unidade do `InstrucaoSql`.
- **Recomendação:** tarefa de endurecimento do `walle-seguranca` antes de ligar em produção.

### B4. Edição de SQL de query que já rodou (Ferramentas, task 6.6)
- O 220 deixa editar o SQL de uma query agendada que já rodou, e o `RoboTarefas` pula os clientes que já têm OK. Opções: (1) manter como no 220; (2) avisar na tela antes de salvar; (3) bloquear e exigir query nova. **Recomendação:** 2.
- A codificação base64 está duplicada entre `Ferramentas` e `RoboTarefas`. Recomendação: mover para o model `TaskSchedulerQuery`.

### B4b. Informações Técnicas: importar do Help Desk (task 8.4), desvios do 220
- O `atualizar.php` do 220 reativava o cliente (`ativo_painel_contingencias='S'`, `deletado='N'`) a cada importação e gravava `porta_http='80'` e `porta_ssh='22'` fixos. Seguindo a recomendação do arquiteto, o walle **preserva o `ativo_painel_contingencias` atual** (linha nova usa `S`) e **senha vazia no Help Desk mantém a senha guardada**. A tela só abre clientes com `deletado='N'`.
- **Opções:** (1) como está, preservando o ativo (recomendada); (2) reativar como o 220 e avisar no texto da confirmação; (3) reativar sem aviso. Confirmar se algum fluxo da operação depende da reativação automática.
- Validação nova: obrigatórios, portas 1 a 65535, `ip_externo_servidor` aceita IP ou nome de host (até 50 caracteres) e os campos Docker só valem com `tips_server` = DO (53 dos 90 clientes LL ativos têm valores legados nesses campos).

### B5. Painel da Contingência BOT só lista clientes com painel
- Cliente cuja primeira coleta falhou não aparece na aba Robô (igual ao 220). O arquiteto sugeriu incluir clientes que só têm coleta com falha e contá-los no KPI de falhas. **Recomendação:** tarefa pequena depois da virada.

### B6. Auditoria dos robôs sem política de retenção
- CleanTax a cada 30 s com 216 clientes gera dezenas de milhares de linhas por dia na auditoria. Falta definir retenção (apagar após N dias) e o status `skipped` para clientes abandonados quando o host fica ocupado. **Recomendação:** definir retenção de 90 dias numa tarefa de manutenção.

### B7. Valores da task 5.1 (decisão já tomada com "seguir a recomendação"; confirmar)
- O crontab real do 220 não é versionado. Foram usados como frequência os intervalos entre ciclos dos daemons: contingências 10 s, Sentinela 10 min, CleanTax 30 s e Task Scheduler 1 min. Sem quarentena nem limite de falhas (o 220 não tem). Token do CleanTax só do `.env` (`CLEANTAX_TOKEN`). O `schedule:work` precisa ficar rodando para os intervalos de segundos.
- **Antes de ligar qualquer `WALLE_*_ATIVO`:** conferir o crontab real do servidor 220 e o token.

## C. Tarefas de limpeza no ledger
- T-0019 (PR #33): bloqueada, substituída pela T-0021. Não retomar.
- Pendências fora do walle (PLAN.md): restringir o `root@%` do 192.168.10.254 e trocar a senha; apagar os logs do 220 que guardam a senha do root.
