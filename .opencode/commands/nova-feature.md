---
description: Implementa uma nova feature — requisitos, impacto, plano aprovado, implementação com TDD, code review e relatórios entregues no chat para docs/.
agent: walle-orquestrador
subtask: false
---

Funcionalidade solicitada:

$ARGUMENTS

Leia `.claude/commands/nova-feature.md` e siga integralmente o fluxo descrito ali, a partir da seção "Fluxo automático", que prevalece sobre o restante, tratando `$ARGUMENTS` como a funcionalidade solicitada. Atue como `walle-orquestrador`, conforme `.opencode/agents/walle-orquestrador.md`.

Adaptações para o OpenCode:

- Delegue com a ferramenta `task` aos subagentes `walle-*`; onde o fluxo fala em Agent ou SendMessage, use `task` e o identificador retornado.
- As políticas, os hooks e os validadores são os mesmos: o plugin `.opencode/plugin/agent-hooks.ts` aplica `.claude/hooks/agent-policies.json` com o `agent-policy.ps1`.
- Perguntas e a aprovação (1. Aprovar / 2. Ajustar / 3. Cancelar) vão em texto no chat, com resposta livre.
- Se o argumento estiver vazio ou vago, pergunte antes de seguir.
