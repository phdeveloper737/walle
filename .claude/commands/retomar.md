---
description: "Retoma o trabalho a partir do ledger (.walle/tarefas): continua as tarefas abertas do passo em que pararam e, se pedido, segue com o PLAN.md"
argument-hint: "[opcional: T-NNNN para retomar uma tarefa só, ou 'e continue' para seguir com o plano]"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md` (seção "Ciclo de uma tarefa").

Pedido: $ARGUMENTS

1. Rode `-Acao status` do `tarefa.ps1` (todas as tarefas, ou só a informada). Ele mostra as integradas (no `origin/main`), as abertas e as bloqueadas (pelos branches no origin), em qualquer máquina.
2. Para cada tarefa `em_execucao` ou `em_revisao` cujo branch tenha um ledger antigo, prefira `-Acao atualizar -Tarefa T-NNNN` ainda no `main` atualizado e limpo, antes de `retomar`. A chamada carrega o código novo e seleciona a tarefa, inclusive quando o branch já contém o main e nenhum commit muda. Nos demais casos, rode `-Acao retomar -Tarefa T-NNNN` (troca para o branch dela; vindo de outro branch, exige árvore limpa). Leia os eventos do `estado.json` e continue do passo seguinte:
   - `aberta` sem `commit`: implementação em andamento ou não começada; confira o diff com o `walle-verificador` e retome a delegação ao `dono` do ledger.
   - `commit` sem `pr`: rode `publicar`.
   - `desbloqueada`: siga pelo `estado` (`em_execucao` ou `em_revisao`) e pelo evento anterior ao bloqueio.
   - `pr`, `ciclo` ou `atualizada`: se há PR, peça o gate completo, a revisão Codex e a revisão do arquiteto para o HEAD atual (vereditos antigos não valem para outro commit) e siga para `integrar`. Sem PR, publique antes dessas validações. Se `integrar` sair com 4, o CI ainda está rodando: rode de novo.
   - Se o branch da tarefa precisa incorporar commits do main, primeiro integre as dependências e termine de commitar o trabalho. Com árvore limpa, rode `-Acao atualizar -Tarefa T-NNNN` a partir do `main` atualizado ou do próprio branch da tarefa; outra tarefa é recusada. O evento `atualizada` preserva a contagem de ciclos e exige novas validações para o HEAD resultante. Em falha anterior ao commit, o comando cancela o merge e preserva o trabalho original; se começou no `main`, retorna a ele. Encaminhe conflitos ao arquiteto e ao dono da tarefa pelo ledger, sem Git de escrita direto. Se só o push falhar, o commit é preservado para publicação pelo ledger.
3. Tarefas `bloqueadas`: liste o motivo (último evento) e pergunte a decisão ao usuário. Com a decisão, grave-a em `.walle/local/T-NNNN/notas.md` no lugar do motivo (o texto do bloqueio é recusado) e rode `-Acao retomar -Tarefa T-NNNN`. `atualizar` recusa tarefa bloqueada; se a retomada selecionar um ledger antigo sem essa ação, relate ao arquiteto e ao usuário, sem executar Git de escrita diretamente nem inventar opção do comando.
4. Não refaça o que o ledger registra como feito. Se o estado local divergir do ledger (ex.: branch apagado, PR fechado sem merge), relate e pergunte antes de agir.
5. Se o pedido incluir continuar o plano, ao terminar as tarefas abertas siga `.claude/commands/executar-plano.md`.
