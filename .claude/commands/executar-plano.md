---
description: "Executa o PLAN.md sozinho, tarefa por tarefa, pelo ledger (.claude/hooks/tarefa.ps1) até o merge de cada uma; para só em decisões do usuário"
argument-hint: "[escopo opcional: uma task (3.2), uma fase (Fase 3) ou um limite (3 tarefas)]"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md`, e segue o "Ciclo de uma tarefa (ledger e Git)" de lá para cada tarefa. Não implemente código; delegue.

Escopo pedido (vazio = o plano inteiro, na ordem):

$ARGUMENTS

## 1. Situação antes de começar

1. Rode `-Acao status` do `tarefa.ps1`: ele lista as tarefas integradas (no `origin/main`), abertas e bloqueadas (pelos branches no origin). Tarefa `em_execucao` ou `em_revisao` é retomada antes de qualquer outra (`-Acao retomar`), do passo em que parou. Tarefa `bloqueada` só volta quando o usuário tiver decidido (registre a decisão em `notas.md` e use `-Acao retomar`).
2. Leia o `PLAN.md` inteiro: sprints, fases, dependências ("Dependências:"), paralelismo e checkboxes. Uma task está feita quando todos os testes críticos dela estão `[x]` (as tasks feitas antes do ledger existir não têm tarefa no ledger e continuam feitas). Uma task com tarefa integrada no ledger também está feita.
3. Leia `docs/harness/roadmap-automacao.md` e as regras (`CLAUDE.md`).

## 2. Escolha da próxima task

- Siga a ordem do plano respeitando as dependências: uma fase só começa quando as fases de que depende estão concluídas (todas as tasks feitas).
- **Nunca abra tarefa** para a seção "Manual, fora da automação" (Classificação Mercadológica: tasks 3.3, 6.4 e 7.2) nem para as "Pendências fora do walle" (ex.: `root@%` do .254); o `tarefa.ps1` também recusa a Mercadológica.
- Task que depende de decisão do usuário ainda não tomada (ex.: valores do 220 sem resposta inequívoca, transporte do certificado na 10.1, montagem do `sistema/app` na 10.2): abra a tarefa, registre a pergunta em `notas.md`, rode `-Acao bloquear`, pergunte no chat com opções numeradas e recomendação, e siga para a próxima task independente.
- Uma task por vez (um implementador por vez nesta sessão).

## 3. Execução de cada task

Siga o ciclo do orquestrador (abrir → contrato quando preciso → implementação → documentação → commitar → publicar → gate → revisão → correção em até dois ciclos → integrar). O `pedido.json` leva `"tipo": "plano"` e `"origem": "PLAN <número da task>"`. Na documentação, marque `[x]` só os testes críticos que o implementador entregou passando; o gate do commit final e o CI comprovam. Data, PR e contagens do gate vão no comentário de revisão do PR, não no `PLAN.md`.

## 4. Fim

Pare quando: o escopo pedido terminou; não há mais task executável (todas feitas, bloqueadas ou manuais); ou o usuário mandar parar. Entregue no chat uma tabela por tarefa (id, task do plano, PR, situação) e as decisões pendentes, com opções numeradas e recomendação.
