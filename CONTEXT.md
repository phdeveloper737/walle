# walle
> Reescrita do sistema legado newsuporte (servidor 220): aplicação interna em que a equipe acompanha e opera processos fiscais e de infraestrutura dos clientes (contingência de NFC-e, autorizações na Sefaz, servidores Sactus), com visibilidade da saúde do próprio sistema e da concorrência de mercado.

> Fonte normativa: `.ai/guidelines/walle.md`; regras e skills seguem essa fonte. As decisões de 24/10/2026 são requisitos documentados, sem atestar adequação do código existente.

## Stack
| Camada | Tecnologia |
|--------|------------|
| Linguagem | PHP 8.5 (FPM) |
| Framework | Laravel 13 |
| Interatividade | Livewire 4 (com Alpine) |
| Estilo / UI | Tailwind CSS 4 + walle-ui (Material Design 3, componentes em `resources/views/components/ui`) |
| Banco de dados | MySQL 9.7 LTS — banco `walle` |
| Sessão, cache e filas | Valkey 9 (compatível com Redis) |
| Filas e agendador | Horizon / Scheduler do Laravel |
| Servidor web | nginx (`walle-web`, `http://localhost:8080`) |
| Login | Help Desk — `a_user` do banco `suporte` em 192.168.10.254 (conexão `help`, somente leitura) |
| Qualidade | Pest (testes) · PHPStan/Larastan (análise estática) · Pint (formatação) |
| Infraestrutura | Docker (contêineres `walle-app`, `walle-web`, `walle-mysql`, `walle-valkey`, `walle-horizon`, `walle-scheduler`, `walle-vite`) |

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
├── routes/                 # rotas (páginas autenticadas que apontam para componentes Livewire)
├── .ai/guidelines/walle.md # regras do projeto; o Laravel Boost replica em CLAUDE.md e AGENTS.md
├── .mcp.json               # MCP do Laravel Boost (Claude Code)
└── .codex/config.toml      # MCP do Laravel Boost (Codex)
```
Demais pastas: `config/` contém configurações; `database/migrations/`, migrations; `tests/Feature/` e `tests/Unit/`, testes; `tests/Migrations/`, testes de estrutura e migrations auxiliares dos bancos isolados de teste; `tests/Fixtures/`, amostras.

## Como rodar localmente
**Backend** (tudo dentro do contêiner; não se instala PHP, Composer ou Node no Windows)
```powershell
cd C:\docker\walle
docker compose up -d
docker exec walle-app php artisan migrate
docker exec walle-app composer require pacote/nome      # para instalar um pacote
docker exec walle-app ./vendor/bin/pest
docker exec walle-app ./vendor/bin/pint --dirty
docker exec walle-app ./vendor/bin/phpstan analyse
docker exec walle-app php artisan optimize:clear        # depois de mudar .env ou config/
```
Endereços: `http://localhost:8080/` (painel), `/saude` (saúde do sistema), `/horizon` (filas). MySQL no Windows: `localhost:3309`. O OPcache revalida arquivos a cada 2 s.

**Frontend**
```powershell
docker compose --profile dev up -d                                            # walle-vite em localhost:5173
docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build   # build dos assets
```

## Padrões de código
- Nomenclatura de arquivos: models com nome em português apontando para a tabela legada (`protected $table = 'c_cliente';`, `public $timestamps = false;` quando não houver `created_at`/`updated_at`); telas e classes de domínio também em português (ex.: `Contingencias`, `RoboAutorizadas`, `ArquivoCfg`).
- Variáveis e métodos: camelCase com nomes descritivos, seguindo arquivos irmãos; classes de domínio e telas em português. Nomes de tabelas e colunas legadas permanecem intactos.
- Estrutura de endpoints: cada página é uma rota autenticada ligada a um componente Livewire (ex.: `/contingencias` → `Livewire\Contingencias`); login em `/entrar`, logout em `POST /sair`; exceções em controller: `SairController` e `LandingFiscalController` (`/landing/fiscal`).
- Estrutura de componentes: telas usam só os componentes `<x-ui.*>` (componente ausente é tarefa própria antes da tela dependente; `<x-ui.tabela>` precede novas telas com tabela, mantendo as existentes até migração explícita); cores só por tokens do tema; ícones Material Symbols via `<x-ui.icone>`; fonte Roboto servida localmente; gráficos `<x-grafico.colunas|linhas|barras>` dentro de `<x-grafico.cartao>`, sempre com uma tabela dos mesmos números.
- Tipagem: retornos e parâmetros explicitamente tipados; PHPDoc com formatos de arrays quando necessário, conforme AGENTS.md e CLAUDE.md.

## TDD
- Framework backend: Pest
- Telas Livewire são testadas com Pest e helpers Livewire; não instalar dependências de navegador sem tarefa explícita.
- Testes em `tests/Feature/`, `tests/Unit/` e `tests/Migrations/`; fixtures em `tests/Fixtures/`.
- Cobertura percentual mínima não definida. Antes de concluir alterações de código, o agente executa Pint nos PHP alterados, PHPStan completo e toda a suíte Pest no Docker. Filtros são intermediários; não delegar a suíte ao usuário. Revisão exclusivamente documental exige consistência e sincronização.
- Testes críticos deste projeto:
  - [ ] Ao receber o código 656 (consumo indevido) da Sefaz, interromper apenas o ciclo que recebeu o retorno, sem novas consultas nele. Outros ciclos e processos continuam, sem bloqueio global por certificado ou liberação manual.
  - [ ] Segredos do sactus.cfg (senha, token) saem mascarados (`ArquivoCfg::mascarar`) e só aparecem reais quando um admin pede.
  - [ ] `Auditoria::registrar()` registra usuário, ação, cliente e IP e nunca grava senha ou valor sensível em `detalhes`.

## Nunca fazer
- Nunca renomear tabela ou coluna vinda do banco legado `suporte` (só acrescentar colunas e tabelas novas).
- Nunca escrever no banco do Help Desk, por qualquer conexão, inclusive `help_escrita`; fixtures em banco isolado de teste são permitidas. Funcionalidades que gravam nesse banco foram retiradas do plano.
- Nunca instalar PHP, Composer ou Node no Windows — tudo roda nos contêineres Docker.
- Nunca gravar senha ou valor sensível em `detalhes` da `Auditoria`.
- Nunca escrever botões, campos ou tabelas soltos com classes Tailwind repetidas, nem usar hexadecimal solto na tela — só `<x-ui.*>` e tokens do tema.

## Regras operacionais aprovadas
- Auditoria em ações humanas do painel, consultas à Sefaz e toda escrita externa permitida, inclusive de robôs. Navegação não é ação operacional; ciclos também têm histórico próprio. Não registrar segredos; escrita no Help Desk continua proibida.
- SQL manual em clientes, inclusive SELECT, só para admin com confirmação válida na sessão, conferida antes da conexão. Jobs agendados seguem a autorização da tarefa cadastrada e são auditados.
- Confirmação de senha reutilizável por `config('walle.confirmar_senha_minutos')` minutos, usando `walle.senha_confirmada_em`; Gate conferido em cada ação.
- Certificados: recebimento, conversão e entrega inteiramente em memória, sem temporários ou persistência em disco, sessão, cache, fila ou log, inclusive na cadeia de upload do servidor web/PHP.
- Skills equivalentes em `.claude/skills/` e `.agents/skills/`; ambas detalham a fonte central sem contrariá-la.
- Parâmetros de robôs (frequência, timeout e quarentena) devem ser transcritos do legado com valor, unidade e referência antes de implementar. Se o legado não resolver, solicitar decisão do usuário.

## Decisões em aberto
- [ ] Modelo de dados completo (migrations novas do walle vs. tabelas legadas herdadas) e contrato de cada Job de fila (`ExecutarAcaoSactus`, `ProcessarClienteSactus`, `RodarCicloAutorizadas`, `RodarCicloSactus`).
- [ ] Critérios de autorização além do `Gate::allows('admin')` (perfis, permissões granulares) e integração completa com o squad ExpxAgents que gera `analise-concorrentes.json`.
