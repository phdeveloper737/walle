# Roadmap de automação do walle

> Decisões do usuário em 26/10/2026: automação total (branch → PR → CI → revisão → merge sem intervenção), exceto a Classificação Mercadológica, que segue manual; RAG e harness devem rodar integralmente em Linux com Docker, sem depender do Windows; memória do RAG recomeçada do zero (as notas antigas estão arquivadas fora do projeto).
> Origem: auditoria de automação de 26/10/2026. Cada fase é um PR com revisão do `walle-arquiteto` e CI verde.

## Situação

| Fase | Objetivo | Situação |
|---|---|---|
| 0 | CI verde no `main`, `main` só por PR | ✅ PRs #7 e #8 |
| 1 | Gate com evidência verificável (`.claude/hooks/gate.ps1`) e merge só com CI verde | ✅ PR #9 |
| 2 | Ledger de tarefas, retomada, Git automático e execução do `PLAN.md` sem intervenção | em andamento |
| 3 | RAG confiável, rodando inteiro em Linux/Docker | pendente |
| 4 | Custo, limites e robustez (timeouts por etapa, concorrência entre gates, logs dos hooks) | pendente |
| 5 | Revisão independente automática em todo PR | parcial: o `walle-arquiteto` revisa e o parecer vai como comentário |
| 6 | Harness portátil: hooks e gate em PowerShell 7/Node, sem Windows | pendente |

## Fase 2 — Ledger, retomada e Git automático

- `.walle/tarefas/T-NNNN/` (`pedido.json` e `estado.json`): estado de cada tarefa (`em_execucao`, `em_revisao` ou `bloqueada`), dono, ciclos de correção, eventos, branch e PR. Versionado no branch da tarefa; integrada é a tarefa cuja pasta está no `origin/main`. Bloqueadas e abertas ficam visíveis pelos branches no origin: a retomada funciona em qualquer máquina.
- `.claude/hooks/tarefa.ps1`: único caminho do orquestrador para o Git (`proxima`, `abrir`, `retomar`, `bloquear`, `commitar`, `publicar`, `integrar`, `status`). O `integrar` faz o merge só com gate `valida_para_commit` para o HEAD, revisão APROVADO do `walle-arquiteto` para o mesmo commit e CI verde, amarrado ao HEAD conferido; é idempotente. O `commitar` conta os ciclos depois do PR e bloqueia no terceiro. Recusa a Classificação Mercadológica.
- `/executar-plano`: tarefas do `PLAN.md` já estão aprovadas; o orquestrador segue em ordem de dependência e para só em decisão do usuário, bloqueio ou na seção manual.
- `/retomar`: continua a partir do ledger.
- `maxTurns` em todos os agentes.
- Aceite: `claude --agent walle-orquestrador -p "/executar-plano ..."` executa uma tarefa pequena de ponta a ponta, sem intervenção, deixando ledger, gate, PR, revisão e merge como evidência.

## Fase 3 — RAG confiável em Linux/Docker

Premissas: servidor de 2 a 4 vCPU, 4 a 8 GB de RAM, SSD, sem GPU; dezenas de milhares de trechos; um usuário.

- **Sistema e imagem:** Debian 13 no servidor; `node:24-*-slim` (Debian, glibc) com versão e digest fixos. Alpine não serve: `onnxruntime-node` e `sqlite-vec` distribuem binários para glibc.
- **Banco:** um só, SQLite com `sqlite-vec` (vetor) e FTS5 (palavras), com metadados e transação no mesmo arquivo. PostgreSQL com pgvector só se passar de ~1 milhão de trechos ou houver vários gravadores; MySQL 9.7 não (busca vetorial indexada é recurso pago); Qdrant/Chroma não (separam metadados e vetores). O arquivo nunca fica em pasta compartilhada com o Windows.
- **Serviços:** `rag` (API HTTP só na rede interna) e `rag-ingestao` (observa o `HEAD` do repositório montado só leitura e reindexa por hash, apagando o que sumiu). Volumes `rag_indice` (regenerável), `rag_notas` (histórico de sessões) e `rag_modelos`. Configuração por `WALLE_RAG_*`, `restart: unless-stopped` e verificação de saúde.
- **Conhecimento:** fatos verificados e decisões em `.walle/conhecimento/` no git, gerados do ledger e do gate; notas de conversa só como histórico; índice sempre regenerável.
- **Busca:** híbrida (FTS5 bm25 + vetor, combinados por RRF), com filtros por status (normativo > verificado > histórico; superado fora), tipo de fonte e commit. Modelo multilíngue (`multilingual-e5-small` ou `paraphrase-multilingual-MiniLM-L12-v2`) só se o conjunto fixo de perguntas mostrar ganho.
- **Cliente dos hooks:** Node (`hook.ts`) falando HTTP com o serviço; sai o `rag.ps1` e a leitura de caminhos do Windows.
- **Operação:** `VACUUM INTO` diário para volume de backup com cópia externa (restic); logs JSON com rotação; `/health` com trechos, documentos, defasagem em relação ao `HEAD` e última ingestão; migrações de esquema por `user_version`.
- Aceite: num host Linux sem Windows, `docker compose up` sobe o RAG e um commit novo aparece na busca sozinho; as perguntas fixas passam no CI.

## Fase 6 — Harness portátil

- Hooks, gate e `tarefa.ps1` em PowerShell 7 (`pwsh`) ou Node; caminhos relativos ao projeto (sem `C:/...`); duplos de teste sem `.cmd`; CI só em Linux.
- Aceite: o ciclo completo de uma tarefa roda num contêiner Linux, sem Windows.

## Pendências da revisão da Fase 1 (para a Fase 4)

Ramos de falha do `Rodar` sem teste; concorrência entre gates paralelos (arquivo na raiz e `historico.jsonl`); timeout por etapa; o OpenCode não roda o `proteger-comandos.ps1`; falha passageira do hook global ("evento ilegível") sem registro do motivo.
