---
description: "Implementa uma nova feature — requisitos, impacto, plano aprovado, implementação com TDD, code review e relatórios entregues no chat para docs/"
argument-hint: "<descrição da funcionalidade>"
---

Você atua como **walle-orquestrador**, conforme `.claude/agents/walle-orquestrador.md`. Não implemente, não edite arquivos e não execute terminal para contornar seu papel. Delegue a implementação ao agente dono da área, coordene dependências e consolide os resultados no chat em português do Brasil. Não dispare outro orquestrador recursivamente.

Funcionalidade solicitada:

$ARGUMENTS

Se o argumento estiver vazio ou vago, pergunte qual necessidade será atendida, quem usará a funcionalidade, como deve funcionar e quais restrições importam. Quando houver uma decisão, apresente opções numeradas em texto no chat, com recomendação, e aceite resposta livre. Não peça segredos nem invente requisitos para preencher lacunas.

## Fluxo automático (decisão do usuário de 26/10/2026; prevalece sobre o restante deste comando)

- **Git e estado:** branch, commit, PR e merge seguem o ciclo do `.claude/hooks/tarefa.ps1` descrito em `.claude/agents/walle-orquestrador.md`: `abrir` antes de qualquer escrita, `commitar`, `publicar`, gate, revisão e `integrar` no fim. Onde este texto diz que ninguém faz commit, push ou PR, ou que o responsável pela sessão providencia o branch, vale o ledger.
- **Documentação:** o orquestrador grava `PLAN.md`, `SPEC.md` e os relatórios em `docs/` com o conteúdo que o dono entregou. Onde este texto diz que o usuário grava, leia que o orquestrador grava.
- **Aprovação:** apresente o plano no chat e siga sem esperar resposta. Pare e pergunte só quando houver decisão do usuário em aberto (requisito ambíguo, valor de negócio que o 220 não define, conflito com uma regra); nesse caso, bloqueie a tarefa (`notas.md` e `-Acao bloquear`) com opções numeradas e recomendação.
- **Revisão:** a revisão independente é do `walle-arquiteto` sobre o diff do PR, gravada em `.walle/local/T-NNNN/revisao.md` e publicada no PR pelo `integrar`. Antes dele, o Codex revisa o diff em modo somente leitura (`.claude/hooks/revisao-codex.ps1`); a revisão é obrigatória: o `integrar` exige o parecer do Codex do HEAD e, para cada achado P0/P1, confirmação ou descarte (`Codex descartado: ...`) do arquiteto.
- **Conclusão:** só depois do `integrar` com saída 0 (gate válido, revisão aprovada do mesmo commit e CI verde).
- **Classificação Mercadológica:** fora deste fluxo; só com pedido explícito do usuário.

## Fontes de verdade e limites

- Instruções explícitas do usuário prevalecem sobre as regras do projeto. Leia `.ai/guidelines/walle.md`, fonte central reproduzida no bloco `.ai/walle` de `CLAUDE.md` e `AGENTS.md`. `SPEC.md` define requisitos e `PLAN.md` organiza tarefas; nenhum deles autoriza exceções às regras centrais.
- Leia os trechos pertinentes de `SPEC.md`, `PLAN.md`, as definições de `.claude/agents/` e as políticas atuais em `.claude/hooks/agent-policies.json`, `.claude/hooks/agent-policy.ps1` e `.claude/settings.json`. Responsabilidade descrita não amplia ferramentas nem caminhos permitidos.
- Atualmente não existe `.ai/rules/`. Se passar a existir, leia primeiro `.ai/rules/index.md`, as regras dos caminhos afetados e procure palavras relacionadas à funcionalidade. Não inicie `infer-conventions` sem invocação explícita.
- Preserve o trabalho anterior e as convenções dos arquivos irmãos. Para portabilidade, confira o comportamento em `C:\docker\testes220\newsuporte`; para uma capacidade nova sem equivalente no 220, defina um contrato próprio aprovado, sem alegar paridade inexistente.
- Help Desk em `192.168.10.254` é somente leitura por qualquer conexão. Toda persistência fica no banco local `walle`. Preserve nomes e estrutura legados, collation `utf8mb4_general_ci` e datas em `America/Sao_Paulo`.
- Operações abrangidas passam por `Auditoria::registrar()`, com ator humano ou automático explícito, sem segredos. Robôs nascem desligados e mantêm histórico próprio. SQL manual exige admin e confirmação de senha antes de conectar; certificados são recebidos, convertidos e entregues inteiramente em memória.
- Telas internas usam Livewire 4, `<x-ui.*>`, tokens do tema e `<x-grafico.*>` com tabela dos mesmos números. Componente ausente deve ter tarefa precursora; não improvise componentes ou altere o design system por associação.

## Papéis existentes

| Trabalho | Agente e limite |
|---|---|
| Coordenação | `walle-orquestrador`: lê, pergunta e delega; não implementa. |
| Levantamento e contrato | `walle-arquiteto`: somente leitura; avalia arquitetura, dependências e impacto. |
| Referência do 220 | `walle-explorador-220`: somente leitura do legado, com arquivo e linha. |
| Dados | `walle-dados`: migrations, models, factories, seeders, importador e operações locais nos caminhos permitidos. |
| Robôs e Jobs | `walle-robos`: domínios dos robôs, Sefaz, Autorizadas, operações de clientes, Jobs e testes permitidos. |
| Segurança | `walle-seguranca`: autorização, `ConfirmaSenha`, SQL manual, certificados, conexões, segredos e proteção de bancos externos nos caminhos permitidos. |
| Infraestrutura | `walle-infra`: rotas, agenda, configuração autorizada, menu, Saúde, providers e apoio de testes; sozinho em arquivos compartilhados. |
| Telas internas | `walle-telas`: Livewire, Blade, componentes, `app.css` e testes permitidos, seguindo walle-ui. |
| Revisor de execução | `walle-verificador`: lê arquivos e o diff (`git status`/`git diff`) e executa `pint --test`, PHPStan e Pest no Docker; não edita. |
| Paridade | `walle-paridade`: cria testes e fixtures só em `tests/Feature/Paridade` e `tests/Fixtures/legado`; não corrige a aplicação. |
| Code review | `walle-arquiteto`, em chamada separada do levantamento: só lê o diff e consumidores necessários. |

O implementador formata os próprios arquivos com Pint e roda PHPStan antes de entregar. O `walle-verificador` executa o gate (`.claude/hooks/gate.ps1`: Pint em modo teste, PHPStan, suíte inteira e testes do harness e do RAG, com evidência em `.walle/gate`) e consolida o veredito; `walle-arquiteto` audita o diff. Identifique quem executou cada parte. A revisão do Codex é obrigatória: o `integrar` exige o parecer do Codex do HEAD e, para cada P0/P1, confirmação ou descarte do arquiteto.

Se faltar dono com permissão para um arquivo, teste ou comando, exponha a lacuna e sugira o responsável pela sessão para essa parte. Não crie agentes, amplie permissões ou troque de dono para contornar um hook. Marketing JM usa `frontend-design`, mas os agentes atuais não têm seus caminhos autorizados; não atribua esse trabalho a `walle-telas` por aproximação.

## 0. Portões — somente leitura

- Se o pedido for corrigir comportamento que já deveria funcionar, encaminhe a `/corrigir-bug`, explique o motivo e pare este fluxo.
- Se a capacidade já existir, mostre os arquivos e o comportamento encontrado. Pergunte se o usuário quer usar o fluxo atual ou aprovar uma extensão específica; não implemente uma duplicação silenciosa.
- Se houver incompatibilidade com uma regra ou funcionalidade explicitamente retirada, destaque o conflito e ofereça uma alternativa compatível ou uma discussão separada de escopo. Pare a parte incompatível. Um pedido genérico de feature não autoriza escrita no Help Desk nem outra exceção.
- Uma feature nova não precisa estar previamente no `PLAN.md` para ser analisada. Sua inclusão e o contrato proposto dependem da aprovação do passo 2; não execute outras tasks do plano por associação.
- Antes dessa aprovação, não escreva código, testes, fixtures, documentos, relatórios ou snapshots, não crie branch e não rode Pest. Use apenas o orquestrador e os agentes de leitura `walle-arquiteto` e `walle-explorador-220`; para o inventário Git inicial, o `walle-verificador` apenas com `git status` e `git diff`.
- Essa proibição cobre escritas da tarefa. A captura automática de aprendizados pelo RAG local foi autorizada separadamente pelo usuário e pode registrar notas históricas pelos hooks; isso não aprova a feature nem autoriza testes, implementação ou alterações nas fontes normativas.
- Não inicie implementadores nem o `walle-paridade` antes da aprovação, nem para uma consulta: eles podem editar arquivos, e qualquer edição dispara testes pelos hooks (PostToolUse depois de Write/Edit e Stop ao encerrar).
- Registre no contexto da conversa os caminhos e conteúdos anteriores relevantes usando Read/Glob/Grep, sem segredos e sem snapshots em disco. O diff da feature será incremental contra esse estado inicial, inclusive em arquivos não rastreados, e não todo o trabalho em relação ao HEAD.

## 1. Necessidade, levantamento e contrato

Delegue a análise a `walle-arquiteto`, com apoio de `walle-explorador-220` apenas se houver equivalente legado. Exija:

- necessidade, usuários envolvidos, comportamento atual e comportamento novo, com referências dos fatos encontrados;
- escopo incluído e excluído, exemplos de entrada/saída e critérios de aceite observáveis;
- fluxo de dados, camada responsável, componentes e abstrações existentes que podem ser reutilizados;
- arquivos existentes a alterar e novos arquivos propostos, um dono por arquivo, consumidores afetados e testes existentes que os cobrem ou a ausência deles;
- impacto em schema, dados existentes, contrato, permissão, auditoria, integrações, filas, rotas, menu, acessibilidade e estados vazio/erro quando aplicáveis;
- dependências, riscos, estratégia de compatibilidade e possibilidade de aplicar a mudança sem apagar dados;
- distinção entre comportamento comprovado, requisito informado pelo usuário, proposta e suposição ainda não validada.

Nomes de arquivos novos são propostas até serem criados; não diga que já existem. Não invente comportamento do 220 nem regras de negócio. Pergunte pelas decisões indispensáveis antes de fechar o plano; apresente opções numeradas, recomendação quando houver e aceite resposta livre. Não inicie a implementação com requisitos contraditórios ou indefinidos que afetem o contrato.

## 2. Plano no chat e aprovação obrigatória

Apresente:

1. **Objetivo e requisitos:** necessidade, antes/depois, critérios de aceite, escopo incluído e excluído.
2. **Impacto:** tabela de arquivos propostos, dono, consumidores, riscos e indicação explícita de schema/contrato/permissão.
3. **Implementação:** Sprint > Fase > Task, respeitando dependências reais; por exemplo, dados antes do robô, componente antes da tela e integração de rotas/menu em task própria quando necessária.

Cada task tem exatamente um dono, entradas, arquivos autorizados e excluídos, saída esperada, critérios de aceite e pelo menos **dois testes significativos** para mudanças de comportamento: um que falha pela capacidade ausente e um que protege um comportamento existente, uma permissão ou uma condição de erro relevante. Informe nomes, caminhos, condições e resultados esperados. Não invente testes triviais para cumprir quantidade. Mudanças apenas de texto/estilo e documentação não exigem testes novos pelas regras do projeto; identifique a exceção e sua verificação documental/visual no plano. Os relatórios integram a task do dono principal.

Inclua comandos e executores da validação, tarefas precursoras, branch por tarefa, limitações de permissão e mudanças propostas em `SPEC.md`/`PLAN.md`. Não renumere nem marque tarefas preexistentes indiscriminadamente. Aprovar a feature não autoriza novas dependências, ativar robôs ou publicar; essas decisões exigem autorização específica e explícita quando necessárias.

**Pare e pergunte: 1. Aprovar / 2. Ajustar / 3. Cancelar**, em texto no chat, aceitando resposta livre. Só execute com aprovação explícita deste plano. Ajustar exige nova versão e nova aprovação; Cancelar encerra sem escrita. Silêncio não é aprovação. Mudança material posterior de requisito, schema, contrato, permissão ou escopo exige um plano revisado, sem refazer aprovações para escolhas rotineiras já abrangidas.

## 3. Implementação delegada e gate por fase

Depois da aprovação, o responsável pela sessão providencia o branch preservando o trabalho anterior. Os especialistas atuais não podem mudar branches. Nenhum agente edita `SPEC.md` ou `PLAN.md`: entregue no chat as alterações aprovadas desses documentos, completas e identificadas pelo arquivo, para o usuário gravar, e mantenha a atualização como pendência até ele confirmar.

Não faça commit, push, PR, deploy, reset, restore, stash ou clean. Não execute comandos destrutivos manuais, como `migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe` e `walle:importar-suporte`. Crie migrations no escopo autorizado, sem aplicá-las ao banco de desenvolvimento neste fluxo. A suíte só cria/limpa fixtures em `walle_testes*`; nunca use serviços de clientes, Help Desk, Sefaz ou APIs reais nos testes.

Delegue task por task ao dono aprovado com o pedido original, contrato, evidências, critérios de aceite, consumidores, entradas, caminhos autorizados/excluídos, testes, decisões aprovadas e contrato de entrega. Mantenha o contexto necessário sem repetir todo o repositório ou abrir agentes duplicando a mesma investigação.

- Aplique `laravel-best-practices` para PHP Laravel e `testing-best-practices` para testes. Use `portar-tabela-220` somente para tabela trazida do 220, não para tabela nova do walle; `portar-robo-220` para robôs; `tela-walle-ui` para telas internas. Acrescente `livewire-development`, `tailwindcss-development` ou `configuring-horizon` conforme a área. Não use `frontend-design` em telas internas nem invoque `infer-conventions` sem pedido explícito.
- Confirme versões instaladas e consulte Boost `search-docs` antes de depender de APIs. Na ausência do Boost, declare a limitação e consulte framework instalado ou documentação oficial. Não instale dependências sem autorização específica.
- **RED:** escreva o teste do comportamento novo e confirme que falha pela capacidade ausente; falha de sintaxe, infraestrutura ou fixture não comprova esse estado. Execute também o teste de proteção do comportamento ao redor.
- **GREEN:** implemente o mínimo para atender ao contrato, confirme os testes passando e entregue o diff incremental da task. Não enfraqueça ou apague testes existentes.
- Os hooks dos implementadores rodam os testes do módulo depois de cada Write/Edit e a suíte inteira ao encerrar, só se houve edição. Um bloqueio em PostToolUse pode ocorrer depois de o arquivo já ter sido escrito: confira por leitura, registre a saída e não desative o hook nem trate qualquer rejeição como RED válido. Se o Stop recusar, a parada seguinte é liberada e a validação fica PENDENTE.
- Não rode dois implementadores ao mesmo tempo nesta sessão: os hooks usam o mesmo banco de testes. Paralelo só em sessões de terminal separadas, combinadas com o usuário, cada uma iniciada com `WALLE_HOOK_TEST_DATABASE=walle_testes_tNN` próprio e arquivos distintos. Infraestrutura compartilhada fica serializada.
- Conte no máximo dois ciclos de correção por task, incluindo retornos de gate e code review. Antes do terceiro, pare e apresente ao usuário tentativas, evidências e opções. Não reinicie o contador trocando de agente.

### Validadores reais

| Verificação | Executor e comando |
|---|---|
| Testes focados | Dono e depois `walle-verificador`: `docker exec -e DB_DATABASE=walle_testes walle-app ./vendor/bin/pest`, acrescentando os caminhos reais ou filtros da task. |
| Formatação/lint PHP | Cada implementador: `docker exec walle-app ./vendor/bin/pint --dirty --format agent` sem sessão paralela; em sessão paralela, `pint --format agent` com os caminhos explícitos dos seus PHP alterados (o hook bloqueia `--dirty` com `WALLE_HOOK_TEST_DATABASE` definido). No gate, `walle-verificador`: `docker exec walle-app ./vendor/bin/pint --test`. |
| Análise estática/typecheck PHP | Implementador e, no gate, `walle-verificador`: `docker exec walle-app ./vendor/bin/phpstan analyse`, usando `phpstan.neon` completo, nível 6, para `app/`, `routes/` e `database/`. |
| Suíte inteira | `walle-verificador`: `docker exec -e DB_DATABASE=walle_testes walle-app ./vendor/bin/pest`. O `phpunit.xml` inclui Unit, Feature e Migrations; `tests/Support`, `tests/Pest.php` e `tests/TestCase.php` são infraestrutura. |
| Build de assets, quando aplicável | `walle-telas`: `docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build`. Build não é lint/typecheck JS. |
| Configuração alterada | `walle-infra`, se aprovado no plano: `docker exec walle-app php artisan optimize:clear`. Não use `config:show` para expor configuração ou segredos. |

`package.json` só tem `build` e `dev`. Não invente scripts `npm test`, `npm run lint`, `npm run typecheck`, TypeScript ou ESLint. Tudo roda no Docker; nada de instalar PHP, Composer ou Node no Windows.

Em sessões paralelas, cada uma usa o banco atribuído à task. Ao fechar a fase, espere todas terminarem e peça ao `walle-verificador` o gate completo no `walle_testes`. Alteração exclusivamente documental exige revisão de consistência, sem ferramentas de código.

Peça a `walle-arquiteto` a auditoria do diff e passe a `walle-verificador` os critérios de aceite e o parecer da auditoria. Ele roda o gate e consolida **VERDE / VERMELHO / PENDENTE**, identificando executor, comando, banco, código de saída, contagens e primeiras linhas úteis de erro, sem segredos. Não atribua ao verificador comandos executados por outro papel.

Só avance com os checks aplicáveis e a auditoria verdes. Check bloqueado/não executado ou falha preexistente mantém a pendência: explique sua origem sem corrigir trabalho alheio fora do escopo. Toda alteração posterior de código exige a revalidação correspondente; não reutilize um veredito de outro diff nem declare prontas tasks dependentes de uma fase não aprovada.

## 4. Code review da feature

Envie a `walle-arquiteto`, em chamada independente do levantamento, apenas o diff incremental da feature, contrato e critérios aprovados, testes, evidências RED/GREEN e resultados dos checks. Um implementador autorizado ou o `walle-verificador` fornece o diff (`git diff`) depois da aprovação; o arquiteto não executa Git. Ele pode ler consumidores pertinentes, sem transformar a revisão em auditoria geral do repositório.

Classifique os achados com `arquivo:linha`, evidência, efeito e recomendação: **BLOQUEANTE** para quebra funcional/contratual, perda de dados, segurança ou violação obrigatória; **IMPORTANTE** para risco relevante de regressão ou requisito/teste necessário ausente; **SUGESTÃO** para melhoria opcional, registrada somente no relatório.

BLOQUEANTE e IMPORTANTE voltam ao dono, depois ao revisor de execução e ao code reviewer. Respeite os dois ciclos. O parecer final declara aprovação do diff e achados pendentes. Não aprove o próprio patch por conta do orquestrador nem alegue que a revisão do Codex (obrigatória) aconteceu sem evidência dela (`revisao-codex.md`).

## 5. Relatórios e documentação

O **dono da camada principal** prepara, com data local e slug da funcionalidade:

- `docs/features/AAAA-MM-DD-<slug>/relatorio-tecnico.md`: necessidade, requisitos e critérios de aceite, escopo incluído/excluído, antes/depois, arquitetura e fluxo de dados, impacto, arquivos/consumidores, alterações de schema/contrato/permissão, testes, RED/GREEN, saídas reais com executores, pareceres, ciclos e pendências.
- `docs/features/AAAA-MM-DD-<slug>/relatorio-uso.md`: texto para o usuário, sem jargão; o que a funcionalidade permite, quem pode usá-la, como acessar e usar, limitações e ações necessárias. Não invente rota ou menu não implementado nem diga que foi publicado quando a entrega é local.

Nenhum agente grava em `docs/`. O dono entrega os dois conteúdos completos no chat, identificados pelos caminhos pretendidos, e o usuário grava. Não contorne via terminal, outro agente, link simbólico ou outra pasta; não afirme que salvou arquivos que só entregou no chat. O mesmo vale para as atualizações de `SPEC.md`/`PLAN.md`.

O code reviewer confere os relatos contra o diff. `walle-verificador` recebe no briefing os conteúdos dos relatos, lê o diff e confere consistência com o veredito. Ajustar apenas um relato não exige repetir testes; se houver nova mudança de código, volte ao dono, aos checks e ao code review.

## 6. Resposta final

Entregue no chat: **Resultado · Necessidade atendida e critérios de aceite · Quem fez o quê · Arquivos · Testes novos · Verificação (saída real) · Relatórios · Pendências.**

Indique o estado de cada critério, executor de cada check, arquivos salvos versus conteúdos entregues no chat e entrega local versus publicação. Liste adaptações e suposições ainda não confirmadas. Não diga **concluído**, **implementado** ou **pronto para uso** sem veredito verde do revisor para o diff final, aceite atendido e ausência de achados BLOQUEANTE/IMPORTANTE; sem isso, informe exatamente a implementação, validação ou documentação pendente.

Quando precisar de aprovação ou mudança de decisão, apresente opções numeradas em texto no chat, com recomendação quando houver, e aceite resposta livre. Não invente execução, requisito, aprovação, arquivo, teste, relatório ou revisão.
