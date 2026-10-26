<laravel-boost-guidelines>
=== .ai/walle rules ===

# walle — regras do projeto

O walle é a reescrita do sistema "newsuporte" (servidor 220) em Laravel. Responda e escreva textos de tela em português do Brasil.

## Fonte das regras e precedência

- Esta é a fonte central das regras do projeto. O bloco `.ai/walle` de `AGENTS.md` e `CLAUDE.md` reproduz este arquivo. Regras genéricas do Boost e skills detalham estas regras sem contrariá-las.
- `SPEC.md` descreve requisitos e `PLAN.md` organiza tarefas; ambos seguem esta fonte. Uma tarefa não autoriza exceções. Instruções explícitas do usuário prevalecem; incorpore decisões aprovadas aqui e nos documentos derivados.
- O RAG local em `.claude/knowledge/` guarda aprendizados históricos, não instruções nem fonte normativa. Confirme as notas com as regras e o código atuais; uma memória não concede permissão nem substitui aprovação, teste ou revisão.
- Skills e referências em `.claude/skills/` e `.agents/skills/` têm conteúdo equivalente. Atualize as duas cópias juntas; preserve os requisitos de invocação explícita de cada skill.
- Decisões de 24/10/2026 são requisitos. Esta revisão documental não altera o código nem atesta sua conformidade; adequações da aplicação exigem tarefa própria.

## Ambiente: tudo roda no Docker (não instale PHP, Composer nem Node no Windows)

| Contêiner | O que é |
|---|---|
| `walle-app` | PHP 8.5-FPM (Laravel) |
| `walle-web` | nginx — http://localhost:8080 |
| `walle-mysql` | MySQL 9.7, banco `walle` — no Windows `localhost:3309` |
| `walle-valkey` | Valkey 9 (compatível com Redis): sessão, cache e filas |
| `walle-horizon` | filas (`php artisan horizon`) |
| `walle-scheduler` | agendador (`php artisan schedule:work`) |
| `walle-vite` | Vite em desenvolvimento (`docker compose --profile dev up -d`) |

Comandos sempre dentro do contêiner:

- Artisan: `docker exec walle-app php artisan <comando>`
- Composer: `docker exec walle-app composer <comando>`
- Testes: `docker exec walle-app ./vendor/bin/pest` (filtrar: `--filter=nome`)
- Formatação: `docker exec walle-app ./vendor/bin/pint --dirty` (em sessão paralela, passe só os arquivos da sua task)
- Análise estática: `docker exec walle-app ./vendor/bin/phpstan analyse`
- npm: `docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm <comando>`

Depois de mudar `.env` ou `config/`, rode `docker exec walle-app php artisan optimize:clear`.
O OPcache revalida arquivos a cada 2 s: uma edição pode levar até 2 s para aparecer.

## Estrutura de pastas

```
walle/
├── app/
│   ├── Autorizadas/        # robô que confere na Sefaz GO as contingências já autorizadas, diagnóstico de chave e consulta por período
│   ├── Concorrentes/       # AnaliseConcorrentes: só lê o analise-concorrentes.json gerado pelo squad ExpxAgents
│   ├── Contingencias/      # conexão com o servidor do cliente e busca do estado de contingência de NFC-e
│   ├── Livewire/           # telas: Entrar, Painel, Contingencias, Autorizadas, Sactus, Concorrentes, Saude
│   │   └── Concerns/       # traits de componente, ex.: ConfirmaSenha (pede a senha do Help Desk de novo)
│   ├── Models/             # models do walle (ex.: Auditoria) e das tabelas legadas do banco suporte
│   ├── Sactus/             # coleta e interpretação do sactus.cfg, ações, rotinas e robô
│   │   └── Ssh/            # conexão SSH com o servidor do cliente (ConectorSsh, ConectorPhpseclib, SessaoSsh)
│   └── Sefaz/              # consulta de situação de NFC-e com certificado digital
├── resources/views/components/
│   ├── ui/                 # componentes Blade do walle-ui (<x-ui.*>)
│   └── grafico/            # gráficos em HTML/SVG gerados no servidor (<x-grafico.*>), sem biblioteca JS
└── routes/                 # rotas (páginas autenticadas que apontam para componentes Livewire)
```

## Banco de dados

- Tabelas que vêm do banco legado `suporte` mantêm **exatamente** o mesmo nome de tabela e de coluna (`c_cliente`, `s_atendimento`, `fk_c_cliente`...). Nunca renomeie. Pode acrescentar colunas e tabelas novas.
- Models com nome em português apontando para a tabela legada: `protected $table = 'c_cliente';` e `public $timestamps = false;` quando a tabela não tiver `created_at`/`updated_at`.
- Collation padrão: `utf8mb4_general_ci` (a mesma das tabelas legadas; evita "Illegal mix of collations").
- Datas são hora local (`America/Sao_Paulo`), sem fuso.
- O banco do Help Desk (`suporte` em 192.168.10.254) é **somente leitura para o walle**, por qualquer conexão, inclusive uma eventual `help_escrita`. A conexão `help` fornece o login (`a_user`). Não implementar escritas, abertura automática de atendimentos, sincronização de VPN, cadastro de versões, atualização de portas ou de software nesse banco. Fixtures em bancos isolados de teste podem ser gravadas; nunca no Help Desk real. A regra é permanente: quando uma funcionalidade do 220 gravava no Help Desk, a versão do walle grava em tabelas próprias do banco `walle`, sem espelhar nada no 192.168.10.254. Trazer essas funcionalidades de volta ao plano é decisão do usuário.
- Regra permanente (24/10/2026): o Help Desk em `192.168.10.254` permanece somente leitura agora e no futuro. Toda funcionalidade que precisar persistir dados deve gravar exclusivamente no banco local `walle`, inclusive dados complementares ou alterações associados a registros consultados no Help Desk; nunca repassar essas gravações ao Help Desk.
- Help Desk sempre ao vivo (decisão de 25/10/2026): o walle não copia tabelas do Help Desk para o banco `walle`; consulta `c_cliente`, `informacao_tecnica_dataserver`, `a_user` e as demais sempre que precisa, pela conexão `help`, com o usuário `walle` do .254, que só tem `SELECT` no banco `suporte`. A senha desse usuário vem só do `.env` (`HELP_DB_SENHA_RESERVA`); a API do Help não é consultada. Nos testes, a conexão `help` usa o banco `<DB_DATABASE>_help`, com as imitações de `tests/Migrations/HelpDesk`.
- A conexão `help` é somente leitura por nome (fora dos testes) e por host (`WALLE_HELP_HOSTS_PRODUCAO`); toda tentativa barrada vai para a auditoria (`help_desk.escrita_barrada`), sem SQL nem valores.

## Ações que mexem fora do walle

- Ações humanas executadas no painel, consultas à Sefaz e toda escrita externa permitida, inclusive de robôs, passam por `Auditoria::registrar()` (ator humano ou identificação explícita da execução automática, ação, cliente e IP quando disponível). Navegação e renderização não são ações operacionais. Ciclos também mantêm histórico próprio; ele não substitui a auditoria dessas operações. Nunca grave senha, token, certificado ou SQL com valores sensíveis em `detalhes`. Escrita no Help Desk continua proibida, mesmo com auditoria.
- Ações de admin: `Gate::allows('admin')` (ids em `WALLE_ADMINS`) e, no Livewire, o trait `ConfirmaSenha` (pede a senha quando não há confirmação válida na sessão). A confirmação é reutilizada durante `config('walle.confirmar_senha_minutos')` minutos a partir de `walle.senha_confirmada_em`; expirou, pede novamente. Verifique o Gate em cada ação, mesmo com confirmação válida. Liste o método em `ACOES_COM_SENHA` e não use injeção de dependência nos parâmetros dele (depois da senha o trait chama o método direto).
- Robôs que agem fora do walle ficam desligados por `.env` (`WALLE_*_ATIVO`) até a virada; com o robô desligado, as ações manuais dele também não rodam.
- Classificação Mercadológica (decisão de 26/10/2026): o robô dá `UPDATE` nos bancos dos clientes, então fica fora de toda automação. Nenhum agente, comando, agenda ou fluxo automático implementa, agenda ou liga essa funcionalidade sem pedido explícito do usuário; ela é a última do plano (seção manual do `PLAN.md`).
- Segredos do `sactus.cfg` (senha, token...) saem mascarados do servidor (`ArquivoCfg::mascarar`); o valor real só vai para a tela quando um admin pede.

- SQL manual nos clientes, inclusive `SELECT`, exige admin e confirmação de senha válida na sessão antes de conectar; sem esses requisitos nenhuma consulta é enviada. Jobs agendados seguem a autorização da tarefa que os cadastrou e continuam sujeitos à auditoria e ao robô ligado.
- Certificados: recebimento do PFX e senha, conversão e entrega do resultado inteiramente em memória. Não criar arquivo temporário nem persistir payload em sessão, cache, fila ou log. O fluxo deve evitar gravação em disco também no servidor web, PHP e mecanismo de upload; upload temporário convencional não atende ao requisito.

## Front-end (walle-ui)

- Visual Material Design 3 implementado com Tailwind 4 e componentes Blade próprios em `resources/views/components/ui/`.
- Telas internas usam `<x-ui.*>` e gráficos `<x-grafico.*>`; não duplique botões, campos ou tabelas com classes Tailwind. Componente ausente entra como tarefa própria antes da tela dependente. Criar `<x-ui.tabela>` antes de novas telas com tabela; tabelas existentes permanecem até uma tarefa explícita de migração.
- Cores só pelos tokens do tema (`bg-surface`, `text-on-surface`, `bg-primary`...), nunca hexadecimal solto na tela.
- Ícones: Material Symbols (`<x-ui.icone nome="..."/>`). Fonte: Roboto. Tudo servido localmente, sem CDN.
- Interatividade com Livewire 4 (e Alpine, que vem junto).
- Landing e páginas de marketing da JM (`resources/views/marketing/`) ficam fora do walle-ui: use a skill `/frontend-design`, seguindo a identidade da marca JM. Nas telas internas, nunca use essa skill; lá vale a skill `tela-walle-ui`.

### Gráficos (`components/grafico/`)

- Sem biblioteca de JS: `<x-grafico.colunas>`, `<x-grafico.linhas>` e `<x-grafico.barras>` são HTML/SVG gerados no servidor, dentro de `<x-grafico.cartao>`.
- Todo gráfico tem a tabela com os mesmos números (`<x-slot:tabela>` com `<x-grafico.tabela>`): a dica do mouse nunca é o único jeito de ler um valor.
- Cores só `grafico-1`, `grafico-2` e `grafico-contexto` (definidas em `@theme static` no `app.css`), sempre sobre o `surface-container-low`. Elas foram validadas para daltonismo e contraste nesse fundo; trocar cor exige validar de novo.
- Duas séries ou mais: legenda no cartão. Uma série: sem legenda, o título diz o que é. Nunca dois eixos Y no mesmo gráfico.
- O filtro de período fica numa linha acima de tudo o que ele recorta, nunca dentro de um cartão.

## Fluxo de trabalho

- Um branch por tarefa e PR no GitHub. O Claude implementa, o Codex revisa e o usuário aprova.
- Antes de concluir qualquer alteração de código, o agente executa Pint nos PHP alterados, PHPStan com a configuração completa do projeto e **toda a suíte Pest** (`./vendor/bin/pest`, que inclui Unit, Feature e Migrations pelo `phpunit.xml`), dentro do Docker, e informa os resultados. Testes filtrados são intermediários; não substituem a suíte completa nem sua execução pelo agente. Se um check falhar ou não puder rodar, relate a pendência e não declare a validação concluída. Edição exclusivamente documental exige revisão de consistência e sincronização, sem executar ferramentas de código.
- `PLAN.md`, `SPEC.md` e `docs/` são gravados pelo usuário. Os agentes entregam no chat o conteúdo completo dessas atualizações, inclusive relatórios e checkboxes comprovados.
- Agentes em paralelo no mesmo diretório:
  - numa mesma sessão do orquestrador não há dois implementadores ao mesmo tempo, porque os hooks usam o mesmo banco de testes; paralelo só em sessões de terminal separadas, cada uma iniciada com `WALLE_HOOK_TEST_DATABASE=walle_testes_tNN`;
  - cada um roda o Pest no seu próprio banco de testes, com `docker exec -e DB_DATABASE=walle_testes_tNN walle-app ./vendor/bin/pest ...` (NN = número da task; o usuário `walle` tem acesso a `walle_testes_*`);
  - Pint roda só nos arquivos da sua task (o hook bloqueia `pint --dirty` em sessão paralela); erro de PHPStan em arquivo de outra task é relatado;
  - nenhum agente edita arquivo de outra task.
  - Falha na suíte completa causada por arquivo de outra task ainda em andamento é relatada, não corrigida.
  - A validação definitiva (Pint, PHPStan completo e suíte inteira no `walle_testes`) roda no gate da fase, depois que todas as tasks terminam.
- Comandos que apagam dados (`migrate:fresh`, `migrate:reset`, `migrate:rollback`, `db:wipe`, `walle:importar-suporte`) só rodam contra um banco `walle_testes*`, nunca contra o banco `walle` de desenvolvimento. O hook `.claude/hooks/proteger-comandos.ps1` bloqueia o resto e também comandos de terminal que exporiam segredos (`.env`, variáveis de ambiente, `config:show`).
- O sistema antigo roda no contêiner `testes220` (http://localhost:8074, código em `C:\docker\testes220\newsuporte`) e serve de referência: o walle deve devolver os mesmos resultados com os mesmos dados.

## Testes (TDD)

- Framework: Pest. Roda com `docker exec walle-app ./vendor/bin/pest` (filtrar: `--filter=nome`).
- Testes críticos deste projeto:
  - Ao receber o código 656 (consumo indevido) da Sefaz, interromper imediatamente apenas o ciclo que recebeu o código, sem novas consultas nesse ciclo. Outros ciclos e processos continuam; não criar bloqueio global por certificado nem exigir liberação manual por causa desse retorno.
  - Segredos do `sactus.cfg` (senha, token) saem mascarados (`ArquivoCfg::mascarar`) e só aparecem reais quando um admin pede.
  - `Auditoria::registrar()` registra usuário, ação, cliente e IP e nunca grava senha ou valor sensível em `detalhes`.

## Decisões em aberto

- Contrato de cada Job de fila (`ExecutarAcaoSactus`, `ProcessarClienteSactus`, `RodarCicloAutorizadas`, `RodarCicloSactus`) e o restante do modelo de dados.
- Critérios de autorização além do `Gate::allows('admin')` (perfis, permissões granulares) e integração completa com o squad ExpxAgents que gera `analise-concorrentes.json`.

=== foundation rules ===

# Laravel Boost Guidelines

## Foundational Context

This application is a Laravel application running on PHP 8.5. Always use the APIs that match the installed major version of each package — do not assume a version.

Before relying on a package's API, confirm its installed version:
- PHP packages: run `composer show --direct` to list direct dependencies with versions, or `composer show <vendor/package>` for a single package.
- JS packages: check `package.json` for the installed versions.

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If a frontend change doesn't show in the UI or you get a "Unable to locate file in Vite manifest" error, run `npm run build` or ask the user to run `npm run dev` or `composer run dev`.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Use `search-docs` before changes that depend on Laravel ecosystem APIs, behavior, configuration, or version-specific syntax. Skip it for copy-only edits and other changes where package documentation is irrelevant. Reuse sufficient results already in context instead of searching again.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Project Rules

- This project contains committed, area-grouped rules in `.ai/rules` when that directory exists, including path-scoped framework guidelines under `.ai/rules/boost`. Before you enter plan mode or create/edit any file, you MUST first: open @.ai/rules/index.md (it maps file globs to rule files), read every rule file whose globs cover the path(s) in scope, and run `grep -rin 'keyword' .ai/rules` to catch what a path match alone misses. Do not write code until you have read and are following every matching rule. If `.ai/rules` does not exist, continue without it.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

=== tests rules ===

# Test Enforcement

- Add or update tests for behavior and logic changes when a test provides meaningful regression coverage.
- Pure copy, styling, and layout-only changes do not require new or updated tests.
- When test coverage applies, run the affected tests and ensure they pass.
- Test the changed behavior and its important failure modes, but do not add tests beyond them.
- Read the `testing-best-practices` skill before writing tests.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `php artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using `php artisan list` and check their parameters with `php artisan [command] --help`.
- If you're creating a generic PHP class, use `php artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `php artisan make:model --help` to check the available options.

## APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `php artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces in PHP without writing JavaScript.
- You can use Alpine.js for client-side interactions instead of JavaScript frameworks.
- Keep state server-side so the UI reflects it. Validate and authorize in actions as you would in HTTP requests.

=== pint/core rules ===

# Laravel Pint Code Formatter

- If you have modified any PHP files, you must run `vendor/bin/pint --dirty --format agent` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/pint --test --format agent`, simply run `vendor/bin/pint --format agent` to fix any formatting issues.

=== pest/core rules ===

# Pest

- This project uses Pest. Create tests with `php artisan make:test --pest {name}`.
- Do not include the test suite directory in `{name}`. Use `SomeFeatureTest`, not `Feature/SomeFeatureTest`.
- Read the `testing-best-practices` skill for guidance on coverage, naming, structure, dependency isolation, and review.
- Do not delete tests or test files without approval. They are part of the application.

## Running Tests

- Run the narrowest set of tests that covers the change. Pass a file path or `--filter=testName` to `php artisan test --compact`.
- Rerun a test after each change to it.
- Run `vendor/bin/pest` to call the test runner directly. It accepts the same file path and `--filter=testName` arguments.
- After the feature tests pass, ask the user to run the complete suite with `php artisan test --compact`.

</laravel-boost-guidelines>
