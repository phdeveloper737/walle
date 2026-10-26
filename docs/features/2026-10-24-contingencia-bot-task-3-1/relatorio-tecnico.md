# Relatório técnico — Contingência BOT, task 3.1

Data: 24/10/2026, horário de referência `America/Sao_Paulo`.

Entrega local no branch `feat/contingencias-robo-ciclo`. A implementação recebeu parecer **VERDE** de `walle-arquiteto`, sem achados BLOQUEANTE ou IMPORTANTE pendentes. O usuário solicitou complementar a documentação da entrega; este relatório não registra aceite final do usuário nem ativação operacional.

## Necessidade, situação anterior e resultado

A task 3.1 do [PLAN.md](../../../PLAN.md) porta a coleta de `contingency.crawler.php` e `set.history.cont.php` do newsuporte/220. O robô precisa atualizar o histórico e os totais locais de contingências sem transformar falha de conexão em resultado zero.

Na retomada, `RoboContingencias` e `ColetaIntegralContingencias` eram classes sem implementação. O Job não implementava `ShouldBeUnique` nem executava um ciclo. Os nove testes existentes falhavam por essas capacidades ausentes. Durante o desenvolvimento também foram identificadas duas inconsistências nas fixtures, detalhadas adiante.

O resultado é um ciclo com coleta integral, trava por cliente, persistência local transacional, auditoria e histórico de sucessos e falhas. A indisponibilidade de um cliente preserva seus últimos dados válidos e permite processar o próximo. O robô continua desligado por padrão; a entrega não criou tela, botão, rota, comando Artisan ou agenda para executá-lo.

## Fontes e limites do escopo

- Regras centrais: [.ai/guidelines/walle.md](../../../.ai/guidelines/walle.md), reproduzidas em `AGENTS.md` e `CLAUDE.md`.
- Requisitos: [SPEC.md, seção 5.2](../../../SPEC.md); tarefas 3.0, 3.1, 5.1 e 6.2 do [PLAN.md](../../../PLAN.md).
- Referência legada: `C:\docker\testes220\newsuporte\system\consultorias\api\tools\services\contingency.crawler.php` e `C:\docker\testes220\newsuporte\system\consultorias\api\set.history.cont.php`.
- Dependências de dados já existentes: `history_contingencia`, `painel_contingency_crawler`, `informacao_tecnica_painel_contingencias` e as tabelas locais `contingencias_ciclos` e `contingencias_coletas`, criadas pela task 3.0.

Esta task não alterou schema, models, permissões, telas, rotas, configuração, dependências ou agendamento. O Help Desk permanece somente leitura. Testes usam bancos e conectores falsos, sem acessar clientes, Help Desk ou Sefaz reais.

## Fluxo de dados e persistência

1. `ligado()` lê `config('walle.contingencias.ativo')`. Chave ausente ou falsa impede ciclo, coleta e Job de realizar operações.
2. `rodarCiclo()` obtém os clientes com cadastro técnico na conexão `help`, em ordem decrescente de id. `coletarCliente(int $clienteId)` processa um cliente e identifica a origem como manual. O ciclo registra início, término, estado e contagens.
3. Antes de coletar ou remover dados de um cliente inativo, o robô adquire `Cache::lock('contingencias:cliente:'.$clienteId, 3700)`. Se estiver ocupado, registra a falha sem conectar nem remover dados.
4. Clientes deletados, sem consultoria ou desativados no painel têm somente `history_contingencia` e `painel_contingency_crawler` locais removidos, sob trava e transação. A operação é auditada; ciclos e coletas anteriores permanecem.
5. `ColetaIntegralContingencias` lê todos os cabeçalhos de `contingencias_pdv` da loja no cliente, fecha a conexão e lê os cabeçalhos Versatus locais. Não utiliza cache, limite de 1.000, itens das vendas ou filtro de autorizações da tela.
6. Depois das leituras, uma transação local grava histórico, totais do painel, sucesso da coleta e auditoria. Uma falha nessa persistência reverte as alterações, registra a coleta com erro e mantém os últimos dados válidos.
7. Falhas de cliente são representadas por mensagens fixas permitidas. O `report()` recebe uma exceção nova, sem a exceção original em `previous`, evitando propagar SQL, bindings ou credenciais. A auditoria identifica origem, robô, ciclo, coleta e estado.

Estados do ciclo: `em_andamento`, `concluido`, `concluido_com_falhas` ou `falha` fatal. Estados da coleta: `em_andamento`, `sucesso` ou `falha`. Totais nulos indicam falha; totais zero indicam coleta concluída sem contingências. O histórico de falhas permanece após um sucesso posterior.

Nesta coleta, a lista `ResultadoContingencias::pendentes` contém os cabeçalhos brutos usados no histórico. Não se deve aplicar a ela o filtro de `history_contingencia_auth` da busca visual.

## Arquivos, responsáveis e consumidores

| Arquivo | Responsável | Comportamento e consumidores |
| --- | --- | --- |
| [app/Contingencias/RoboContingencias.php](../../../app/Contingencias/RoboContingencias.php) | `walle-robos` | Ciclo, coleta individual, trava, histórico, métricas e auditoria. Consumido pelo Job e pelos testes. |
| [app/Contingencias/ColetaIntegralContingencias.php](../../../app/Contingencias/ColetaIntegralContingencias.php) | `walle-robos` | Leitura integral dos cabeçalhos, injetada no robô. |
| [app/Jobs/RodarCicloContingencias.php](../../../app/Jobs/RodarCicloContingencias.php) | `walle-robos` | Job com `ShouldBeUnique`, conexão `robos` e fila `contingencias`. Integração com agenda permanece na task 5.1. |
| [tests/Feature/Contingencias/RoboContingenciasTest.php](../../../tests/Feature/Contingencias/RoboContingenciasTest.php) | `walle-robos` | 16 cenários com dados e conexões isolados. |
| [PLAN.md](../../../PLAN.md) e os dois relatórios desta pasta | Responsável pela sessão Codex | Registro dos resultados, critérios comprovados e documentação solicitada. |

As outras alterações já presentes no workspace não integram esta entrega. As migrations e os models da task 3.0 são dependências anteriores, não alterações produzidas pela task 3.1.

## Contrato legado, adaptações e limitações

| Tema | Evidência e decisão preservada |
| --- | --- |
| Coletar ambos os softwares | `set.history.cont.php:292` força `tips_pdv = 'A'` antes dos ramos. O coletor mantém Sactus e Versatus mesmo que o cadastro local contenha outro tipo; não replica a escrita desse campo. |
| Versatus | Linhas 301–346: regrava a cada coleta; exclui motivo que contenha `Nao Enviada para Sefaz`. Nas linhas 330–332, número é convertido para inteiro e usado como chave alternativa quando necessário. `000123` passa a `123` na persistência. |
| Sactus | Linhas 430–450: leitura de todos os cabeçalhos da loja; histórico com quantidade positiva igual permanece. Sactus zero com Versatus positivo também mantém o histórico Sactus anterior, conforme os ramos 439–448 e 547. Ambos zero limpam o histórico, conforme a linha 566. |
| Chave ausente no Sactus | Linhas 476–489: mantém geração de chave sintética com cliente, índice e tempo. O índice não reinicia entre lotes. Não presume que essa chave seja uma NFC-e válida. |
| Escrita em lotes | Linhas 322 e 470: lotes de 100. O walle utiliza `REPLACE` parametrizado e preserva os nomes das tabelas e colunas. |
| Unicidade global | `history_contingencia.chave` continua globalmente única. A convenção legada de chave alternativa do Versatus é mantida; esta entrega não redefine sua unicidade por cliente. |
| Job e trava | Padrão de `app/Jobs/RodarCicloSactus.php`: timeout 3.600 s, uma tentativa e unicidade 3.600 s. A trava de cliente dura 3.700 s, acompanhando o `retry_after` existente da conexão `robos` em `config/queue.php`. |
| Conexão ao cliente | Reutiliza `ConectorDeCliente`: padrão configurável de 10 s, verificação de porta com metade desse valor e sessão de 30 s. A referência `ClienteConnection.class.php:439` usa conexão de 20 s; as linhas 533–535 usam sessão de 30 s. A adaptação de transporte já existente foi mantida, sem alterar configuração. |
| Controles do crawler | `contingency.crawler.php:11–16,202–203,247–255`: intervalo 10 s, concorrência 3, timeout HTTP 600 s, conexão HTTP 15 s, três retries além da tentativa inicial e watchdog 600 s. São parâmetros do daemon legado, não controles implementados por esta task; a integração operacional fica na task 5.1. Não foi identificada quarentena nesse crawler. |
| Escritas auxiliares do legado | Não copia credenciais, não altera CRT no cliente e não grava no Help Desk. Histórico e auditoria ficam no banco local do walle. |

As limitações herdadas de Sactus e da chave global não foram reinterpretadas como melhorias aprovadas. Sua eventual alteração requer decisão própria do usuário.

## Critérios de aceite e cobertura

Os 16 cenários estão em [RoboContingenciasTest.php](../../../tests/Feature/Contingencias/RoboContingenciasTest.php). Há 15 declarações de teste; o caso de 1.001 registros roda com e sem chave e produz dois cenários.

| Critério | Evidência | Estado |
| --- | --- | --- |
| Três contingências geram histórico e métricas correspondentes | Caso de coleta integral com Sactus, Versatus e chave já autorizada | Verde |
| Falha preserva os dados válidos e não impede o próximo cliente | Falha de conexão com outro cliente coletado | Verde |
| Desligado não conecta nem grava ciclo, coleta ou auditoria | Robô, Job e coleta individual desligados | Verde |
| Coleta excede o limite da tela | 1.001 registros com chaves distintas e 1.001 sem chave | Verde |
| Zero confirmado é diferente de falha | Limpeza com totais zero e manutenção das coletas anteriores | Verde |
| Regras de atualização Sactus e Versatus reproduzem o legado | Quantidade igual, Sactus zero com Versatus positivo, cupom com zeros e tipo local sobrescrito no fluxo legado | Verde |
| Limpeza não concorre com coleta | Cliente inativo com trava ocupada mantém seus dados; outro cliente é coletado | Verde |
| Persistência é atômica e erros são seguros | Exceção durante auditoria de sucesso reverte histórico/métricas e só reporta mensagem sanitizada | Verde |
| Sucesso posterior não apaga falhas | Sequência falha → sucesso mantém ambas as coletas | Verde |
| Falta de cadastro não dispara conexão externa | Aviso seguro, coleta de falha e histórico/métricas preservados | Verde |
| Job tem o contrato de fila aprovado | `ShouldBeUnique`, conexão `robos`, fila `contingencias`, execução quando ligado | Verde |

## TDD, rodadas e correções

As métricas abaixo foram comunicadas por `walle-robos` no chat durante a execução de 24/10/2026. São resumos da execução do implementador, sem arquivo anexo de console integral e sem repetição independente pelo revisor.

| Rodada registrada em 24/10/2026 | Resultado informado pelo implementador |
| --- | --- |
| RED inicial, antes da implementação | 9 falharam, 8 assertions; 54,80 s. Métodos ausentes e Job sem unicidade. |
| Primeiro GREEN parcial | 7 passaram, 2 falharam; 61 assertions; 55,77 s. As duas falhas expuseram inconsistências das fixtures. |
| Rodada intermediária | 13 passaram, 87 assertions; 55,63 s. Não substitui a rodada final após consolidar a revisão. |
| Versão consolidada | 15 passaram, 93 assertions; 58,47 s. |
| Caso adicional de cadastro ausente | 1 passou, 10 assertions; 40,33 s. Apenas teste adicional, sem mudança na implementação. |
| Gate completo | 243 passaram, 1.033 assertions; 257,54 s; exit 0. |

As correções das fixtures mantiveram as exigências originais:

- O preenchimento com `2` gerava chaves iguais para números diferentes. Foram geradas 1.001 chaves distintas, mantendo a exigência de 1.001 linhas; o caso sem chave foi acrescentado.
- A verificação do cliente consultava a conexão padrão, enquanto a fixture estava na transação `help`. A asserção passou a indicar `help`, comprovando que o cadastro permanece intacto.

O code review identificou a normalização do número Versatus e de sua chave alternativa. A correção foi incorporada com teste de `000123` → `123`. A revisão também levou à cobertura de rollback, limpeza sob trava, sucesso após falha e cadastro ausente. Não houve terceiro retorno de correção com falha; as rodadas adicionais documentadas ampliaram a cobertura ou executaram o gate final.

## Verificação — saídas reais e executores

`walle-robos` executou Pint nos quatro arquivos PHP da task, PHPStan com a configuração completa e a suíte inteira no Docker. `walle-arquiteto` fez revisão somente leitura, e o responsável pela sessão Codex conferiu código, evidências e registro da entrega. Não houve execução independente de Pest por `walle-verificador`; não se atribui a ele nenhum check desta entrega.

Saída registrada do Pint:

```text
{"tool":"pint","result":"passed"}
```

Saída registrada do PHPStan completo:

```text
[OK] No errors
```

Comando efetivamente executado no gate:

```powershell
docker exec -e DB_DATABASE=walle_testes walle-app ./vendor/bin/pest tests/Unit tests/Feature tests/Migrations --compact
```

Resumo real recebido dessa execução:

```text
243 passed (1033 assertions)
257.54s
exit 0
```

O resumo acima não é uma transcrição integral do console. Os comandos abaixo reproduzem o escopo de formatação e análise estática; não foram executados novamente para produzir este relatório:

```powershell
docker exec walle-app ./vendor/bin/pint app/Contingencias/RoboContingencias.php app/Contingencias/ColetaIntegralContingencias.php app/Jobs/RodarCicloContingencias.php tests/Feature/Contingencias/RoboContingenciasTest.php --format agent
docker exec walle-app ./vendor/bin/phpstan analyse
```

## Pareceres, documentação e pendências

Parecer final de `walle-arquiteto`, registrado no chat: **“VERDE — revisão final da task 3.1 aprovada.”** Sem achados BLOQUEANTE ou IMPORTANTE restantes. O parecer distingue a revisão por leitura dos gates executados pelo implementador. Nenhum teste de integração usou serviços reais.

Esta complementação cria o presente relatório e o [relatório de uso](relatorio-uso.md), e inclui os links no plano. Sua validação é documental: consistência com o código, os requisitos, os resultados já observados e os destinos dos links. Não há nova execução de Pint, PHPStan ou Pest nesta alteração exclusivamente documental. Consulte o registro da task no plano e a resposta da entrega para o parecer documental, separado do parecer da implementação.

Pendências fora da task 3.1:

- Task 5.1: configuração operacional, integração com agenda e filas dos robôs novos. Nenhum comando Artisan futuro descrito no plano deve ser tratado como disponível agora.
- Task 6.2 e suas dependências: apresentação dos resultados e controles do robô no painel.
- Ativação operacional: o robô permanece desligado; aprovação técnica não o liga.
- Validação contra integrações reais e aceite final do usuário não foram realizados nesta entrega.
- As demais tasks da Fase 3 não são declaradas concluídas por este relatório.

Adaptações documentais: estrutura `docs/features/AAAA-MM-DD-<slug>/` prevista em `.claude/commands/nova-feature.md`; separação entre relato técnico e relato para o usuário; criação pela sessão responsável a partir do relatório do implementador e dos pareceres já registrados. Não se ampliaram as permissões dos agentes.

Suposições adotadas: o pedido “Documentação e relatório da entrega” foi interpretado como solicitação desses dois arquivos e da atualização de seus links no plano, sem mudança funcional. Não foi presumido aceite do usuário, autorização de ativação ou conclusão de outra task.
