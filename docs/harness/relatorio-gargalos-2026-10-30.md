# Relatório de gargalos do harness — 30/10/2026

## Objetivo e método

Esta auditoria cobre o gate, os hooks de política, o ledger de tarefas, o CI e o RAG local. A análise combinou leitura do código, histórico de evidências e execuções focadas em ambiente isolado. Nenhuma credencial foi lida ou registrada.

## Resumo executivo

O harness protege bem o fluxo de merge, mas seu custo e alguns ramos de falha reduzem a confiabilidade operacional. Em 109 execuções históricas do gate, a mediana foi de 433 s, o percentil 95 de 594,2 s e o máximo de 627 s. Pest, políticas e ledger concentram a maior parte desse tempo.

Os riscos mais importantes encontrados foram:

1. uma sessão paralela falhava antes dos testes quando `walle_testes_tNN` ainda não existia;
2. as etapas independentes do gate rodavam em série;
3. payload ilegível ou incompleto no protetor de comandos era aceito, e a liberação de comandos destrutivos dependia de correspondência ampla;
4. testes das políticas e do ledger abrem muitos processos PowerShell, aumentando bastante o tempo total;
5. CI, retenção de evidências, observabilidade e RAG ainda não possuem limites e métricas suficientes.

## Plano priorizado

| Prioridade | Melhoria | Resultado esperado | Situação |
|---|---|---|---|
| P0 | Criar com conexão de servidor os bancos `walle_testes_tNN` e `_help` antes de conectar neles | sessões paralelas começam de forma determinística | implementada na T-0041; validação final pendente |
| P0 | Fazer o protetor de comandos falhar fechado e aceitar destrutivos somente em forma simples e ancorada | elimina bypass por payload, prefixo ou comando composto | implementada na T-0041; validação final pendente |
| P1 | Executar etapas independentes do gate em pool limitado, preservando ordem da evidência e trava do Pest | reduzir o tempo de parede sem enfraquecer o gate | implementada na T-0041; validação final pendente |
| P1 | Agrupar casos dos testes de políticas e ledger no mesmo processo | remover o custo de centenas de inicializações do PowerShell | próxima tarefa |
| P1 | Separar jobs do CI e reutilizar caches imutáveis de Composer, npm e imagens | feedback mais rápido e falhas mais localizadas | próxima tarefa |
| P1 | Remover duplicidade entre hooks globais e hooks por agente | uma única execução de cada barreira | próxima tarefa |
| P1 | Adicionar timeout por etapa, motivo estruturado e estado terminal no ledger | impedir execuções órfãs e melhorar diagnóstico | próxima tarefa |
| P2 | Aplicar retenção a histórico, saída completa do Pest e logs, mantendo o último válido | impedir crescimento sem limite | próxima tarefa |
| P2 | Padronizar UTF-8 em todos os processos e artefatos | eliminar texto corrompido nos relatórios | iniciado na T-0041 |
| P2 | Evoluir o RAG para busca híbrida, `topK` configurável, categorias e benchmark fixo | recuperar mais contexto relevante e medir regressões | próxima tarefa |
| P2 | Publicar métricas por etapa, cache, fila, taxa de falha e duração | acompanhar ganhos e detectar regressões | próxima tarefa |
| P1 | Criar ponto de entrada Docker neutro ao provedor e retomada baseada no ledger | qualquer IA autorizada testa e continua quando outra atingir limite | próxima tarefa |

## Critérios que não podem ser enfraquecidos

- O merge continua condicionado ao gate válido para o mesmo commit, revisão Codex, parecer do `walle-arquiteto` e CI verde.
- Comandos destrutivos continuam restritos a `walle_testes` ou `walle_testes_tNN`.
- O Help Desk permanece somente leitura; os testes usam exclusivamente a imitação `<DB_DATABASE>_help`.
- Paralelismo não autoriza dois processos Pest no mesmo banco.
- A Classificação Mercadológica permanece fora da automação.
- O executor de testes não depende da identidade da IA; todos usam os mesmos comandos, travas, banco isolado e evidência versionada/estruturada.

## Validação adicional: Contingências ativas

O usuário informou em 30/10/2026 que definiu `WALLE_CONTINGENCIAS_ATIVO=true` para esta rodada. O valor real não deve ser copiado para arquivos versionados nem exibido pelo harness; esta seção registra somente a decisão operacional.

A validação deve ocorrer nesta ordem:

1. executar os testes com conectores e servidores falsos de `tests/Feature/Contingencias`, cobrindo ciclo, falha isolada por cliente, Job único, auditoria e persistência somente no banco local;
2. confirmar pelo agendador que o comando de Contingências está elegível com a chave ativa, sem usar `config:show` nem imprimir o ambiente;
3. executar no máximo um ciclo controlado, somente após os testes verdes, preservando a auditoria automática e observando Horizon, duração, clientes processados e falhas isoladas;
4. verificar que nenhuma escrita foi tentada no Help Desk e que dados persistidos pelo ciclo ficaram no banco `walle`;
5. registrar o resultado e, ao encerrar a janela de teste, decidir explicitamente se a chave continuará ativa.

Ativar a chave não libera ações humanas de administrador, SQL manual nem qualquer funcionalidade fora do escopo do robô. Essas operações continuam sujeitas a Gate, confirmação de senha e auditoria.

## Evidências da T-0041 até esta revisão

- testes do gate: 51 casos aprovados;
- testes das políticas: 448 casos aprovados;
- Pest focado no bootstrap do banco: 17 testes e 50 asserções aprovados;
- Contingências com a permissão informada ativa: 83 testes e 378 asserções aprovados;
- contrato do agendador: 10 testes e 30 asserções aprovados; `schedule:list` confirmou `walle:robo-contingencias` no intervalo de 10 s;
- Pint nos quatro arquivos PHP alterados: aprovado;
- PHPStan completo: aprovado sem erros.

O gate definitivo continua sendo a evidência de conclusão: primeiro o commit, depois `.claude/hooks/gate.ps1`, com `valida_para_commit: true`.
