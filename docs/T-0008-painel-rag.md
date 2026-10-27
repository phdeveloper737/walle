# T-0008 — Painel do cérebro do RAG

Pedido do usuário de 27/10/2026. Implementação local em /conhecimento, menu Cérebro do RAG, acesso exclusivo de admin.

## Comportamento

- Memórias com filtros por agente, categoria e conteúdo, paginação de 24 trechos e seleção para leitura.
- Grafo SVG circular com conexões por similaridade cosseno de pelo menos 65%, até 120 arestas por página. A distância visual entre os nós não mede similaridade.
- Tabela com as mesmas conexões e lista de memórias como alternativas acessíveis; grafo com rolagem em telas estreitas para preservar alvos de interação.
- Leitura do SQLite existente com SQLITE_OPEN_READONLY, considerando WAL; nenhum embedding vai para o navegador.
- Conteúdo escapado; avisos para índice ausente/corrompido e vazio. Não altera os hooks de captura ou de recuperação.

## Validação

- Pint dos arquivos alterados: passou.
- PHPStan completo: passou.
- Testes do módulo em walle_testes_t08: 7 passaram, 51 assertions.
- Build: passou.
- Leitura real pelo serviço: 30 documentos e 194 trechos; primeira página com 24 nós e 29 arestas.
- Suíte completa no gate preliminar isolado: 396 passaram, 1555 assertions.
- Todas as oito etapas do gate preliminar passaram: Pint, PHPStan, Pest, políticas (411 casos), gate (34), ledger/tarefa (104), plugin (6) e RAG (13).
- O gate preliminar terminou com exit 1 por mudança da árvore durante a execução, incluindo a criação deste relatório pelo orquestrador. A evidência ultimo-walle_testes_t08.json é preliminar e valida_para_commit=false; não autoriza integração. Não houve falha nas etapas de teste.
- Conferência visual não executada: navegador/Computer Use indisponível nesta sessão.

## Integração

O trabalho da T-0008 ficou na mesma árvore da T-0009 (Task Scheduler, task 3.5) porque duas sessões
compartilharam o checkout. Por decisão do usuário de 27/10/2026, as duas entram no mesmo PR, pelo
ledger da T-0009: um commit, o gate definitivo vinculado a esse commit, a revisão do walle-arquiteto e
o CI valem para as duas. O branch `tarefa/T-0008-painel-do-cerebro-do-rag` fica sem código e não
deve ser retomado.
