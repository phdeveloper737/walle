# Economia de tokens no harness

Decisão do usuário de 26/10/2026: reduzir ao máximo o consumo de tokens do projeto.

## Medição que motivou

Duas tarefas pequenas (T-0001, 13.1b, e T-0002, 13.1c) gastaram cerca de 670 mil tokens em subagentes:

| Causa | Efeito |
|---|---|
| Política de arquivos por agente | A 13.1b precisou de 4 implementadores em sequência (infra, dados, segurança, infra), cada um recarregando o contexto, para mudanças de poucas linhas (~140 mil tokens a mais) |
| Arquiteto em opus no contrato e na revisão | ~290 mil tokens nas duas tarefas |
| Skills pré-carregadas fora do assunto | ex.: `tela-walle-ui` no `walle-seguranca` em tarefa sem tela |
| Testes do módulo a cada edição | na fase RED cada edição é barrada e volta ao agente, gerando turnos extras |
| Suíte repetida pelo agente | o hook de Stop e o gate já rodam a suíte inteira |
| RAG com limiar baixo | 3 trechos por mensagem, muitas vezes fora do assunto (similaridade mínima 0,25) |

## Aplicado na T-0003 (pelo `walle-harness`)

- Skills sob demanda: cada agent pré-carrega no máximo a skill central do domínio; as demais são lidas só quando a tarefa toca o assunto.
- `walle-arquiteto` e `walle-orquestrador` no sonnet; o orquestrador passa `model: opus` ao arquiteto só em tarefa sensível (segurança, Help Desk, segredos, certificados, escrita fora do walle). `walle-seguranca` continua no opus.
- Seção "Economia de tokens" no orquestrador: contrato só com ambiguidade real, prompts enxutos, relatórios de até 20 linhas, uma delegação ao dono em vez de revezar agentes.
- Regra da suíte: o implementador roda Pint, PHPStan e os testes do módulo; a suíte inteira fica com o hook de Stop e o gate.

## Pendente: só o usuário pode aplicar

Estes arquivos são barrados para todos os agentes (hooks, políticas e scripts do RAG). Aplicar numa sessão sem a política do orquestrador e rodar os testes de cada um (`agent-policy.Tests.ps1`, `node --test` do RAG e o gate).

### 1. Política de arquivos (`.claude/hooks/agent-policies.json`)

Deixar o `walle-seguranca` editar os arquivos de apoio que as tarefas de segurança costumam exigir, para não revezar agentes. Na regex `arquivos` dele, acrescentar à alternativa:

```
|tests/TestCase\.php|app/Models/Auditoria\.php
```

Atualizar também a seção "Responsabilidades/Arquivos" do `walle-seguranca.md` (tarefa do `walle-harness`) e os casos de `agent-policy.Tests.ps1`.

### 2. Testes a cada edição (`.claude/hooks/agent-policy.ps1`, linhas ~220-248)

No `PostToolUse`, só gravar o marcador de edição e sair, sem rodar o Pest do módulo. O `Stop` continua rodando a suíte inteira (e o gate continua obrigatório para o merge). Efeito: acaba o bloqueio a cada edição na fase RED e o tempo de espera por edição.

```powershell
if ($Event -eq 'PostToolUse') {
    if (-not ($edicao -or $ehMake)) { exit 0 }
    [IO.Directory]::CreateDirectory((Split-Path -Parent $marcador)) | Out-Null
    [IO.File]::WriteAllText($marcador, [DateTime]::Now.ToString('o'))
    exit 0
}
```

Ajustar os casos correspondentes em `agent-policy.Tests.ps1`.

### 3. RAG (`.claude/scripts/`)

- `rag.config.json`: `"topK": 1` e `"minimumSimilarity": 0.45` (no MiniLM, 0,25 deixa passar trechos sem relação).
- `search.ts`, em `search()`: não buscar para mensagens curtas (respostas como "1", "continue"):

```ts
if (safeQuestion.length < 25) { return []; }
```

- Rodar os testes do RAG (`node --test` da etapa RAG do gate) e ajustar os que fixam `topK: 3`.
