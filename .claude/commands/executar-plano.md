---
description: "Executa o PLAN.md sozinho, tarefa por tarefa, pelo ledger (.claude/hooks/tarefa.ps1) até o merge de cada uma; para só em decisões do usuário"
argument-hint: "[escopo opcional: uma task (3.2), uma fase (Fase 3) ou um limite (3 tarefas)]"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md`, e segue o "Ciclo de uma tarefa (ledger e Git)" de lá para cada tarefa. Não implemente código; delegue.

Escopo pedido (vazio = o plano inteiro, na ordem):

$ARGUMENTS

## 1. Situação antes de começar

1. Rode `-Acao status` do `tarefa.ps1` e leia `.walle/tarefas/*/estado.json`. Tarefa `em_execucao` ou `em_revisao` é retomada antes de qualquer outra, do passo em que parou. Tarefa `bloqueada` só volta quando a causa do bloqueio estiver resolvida (decisão do usuário registrada no chat ou no `PLAN.md`).
2. Leia o `PLAN.md` inteiro: sprints, fases, dependências ("Dependências:"), paralelismo e checkboxes. Uma task está feita quando todos os testes críticos estão `[x]` e há tarefa integrada no ledger para ela (`origem: PLAN <n>`).
3. Leia `docs/harness/roadmap-automacao.md` e as regras (`CLAUDE.md`).

## 2. Escolha da próxima task

- Siga a ordem do plano respeitando as dependências: uma fase só começa quando as fases de que depende estão concluídas (todas as tasks integradas).
- **Nunca** escolha tasks da seção "Manual, fora da automação" (Classificação Mercadológica) nem as que dependem de decisão do usuário ainda não tomada (ex.: valores do 220 sem resposta inequívoca, transporte do certificado na 10.1, montagem do `sistema/app` na 10.2). Para essas: abra a tarefa, registre a pergunta em `notas.md`, rode `-Acao bloquear`, pergunte no chat com opções numeradas e recomendação, e siga para a próxima task independente.
- Uma task por vez (um implementador por vez nesta sessão).

## 3. Execução de cada task

Siga o ciclo do orquestrador (abrir → contrato quando preciso → implementação → documentação → commitar → publicar → gate → revisão → correção em até dois ciclos → integrar). O `pedido.json` leva `"tipo": "plano"` e `"origem": "PLAN <número da task>"`. Na documentação, marque `[x]` só os testes críticos comprovados pelo gate e acrescente na task a linha de verificação (data, PR, contagens do gate).

## 4. Fim

Pare quando: o escopo pedido terminou; não há mais task executável (todas feitas, bloqueadas ou manuais); ou o usuário mandar parar. Entregue no chat uma tabela por tarefa (id, task do plano, PR, situação) e as decisões pendentes, com opções numeradas e recomendação.
