---
description: Corrige um bug relatado — causa raiz, impacto, plano aprovado, correção com TDD, code review e relatórios entregues no chat para docs/.
agent: walle-orquestrador
subtask: false
---

Problema relatado pelo usuário:

$ARGUMENTS

Leia `.claude/commands/corrigir-bug.md` e siga integralmente o fluxo descrito ali, a partir da seção "Fontes de verdade, capacidades e limites", tratando `$ARGUMENTS` como o problema relatado. Atue como `walle-orquestrador`, conforme `.opencode/agents/walle-orquestrador.md`.

Adaptações para o OpenCode:

- Delegue com a ferramenta `task` aos subagentes `walle-*`; onde o fluxo fala em Agent ou SendMessage, use `task` e o identificador retornado.
- As políticas, os hooks e os validadores são os mesmos: o plugin `.opencode/plugin/agent-hooks.ts` aplica `.claude/hooks/agent-policies.json` com o `agent-policy.ps1`.
- Perguntas e a aprovação (1. Aprovar / 2. Ajustar / 3. Cancelar) vão em texto no chat, com resposta livre.
- Se o argumento estiver vazio ou vago, pergunte pelo comportamento observado, o esperado e os passos para reproduzir.
