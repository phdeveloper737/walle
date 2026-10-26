---
description: "Retoma o trabalho a partir do ledger (.walle/tarefas): continua as tarefas abertas do passo em que pararam e, se pedido, segue com o PLAN.md"
argument-hint: "[opcional: T-NNNN para retomar uma tarefa só, ou 'e continue' para seguir com o plano]"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md`.

Pedido: $ARGUMENTS

1. Rode `-Acao status` do `tarefa.ps1` (todas as tarefas, ou só a informada) e leia o `estado.json` de cada uma, o `git status` (pelo `walle-verificador`) e `.walle/gate/ultimo.json`.
2. Para cada tarefa `em_execucao` ou `em_revisao`, descubra o último passo concluído pelos eventos do ledger e continue do seguinte:
   - `aberta` sem `commit`: implementação em andamento ou não começada; confira o diff e retome a delegação.
   - `commit` sem `pr`: rode `publicar`.
   - `pr`: peça o gate e a revisão para o HEAD atual (vereditos antigos não valem para outro commit) e siga para `integrar`.
   - `ciclo`: a correção estava em andamento; retome com o mesmo dono.
3. Tarefas `bloqueadas`: liste o motivo (`eventos`) e só retome se a decisão já tiver sido tomada.
4. Não refaça o que o ledger registra como feito. Se o estado local divergir do ledger (ex.: branch apagado, PR fechado), relate e pergunte antes de agir.
5. Se o pedido incluir continuar o plano, ao terminar as tarefas abertas siga `.claude/commands/executar-plano.md`.
