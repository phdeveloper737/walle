# walle
> Reescrita do sistema legado newsuporte (servidor 220): aplicação interna em que a equipe acompanha e opera processos fiscais e de infraestrutura dos clientes (contingência de NFC-e, autorizações na Sefaz, servidores Sactus), com visibilidade da saúde do próprio sistema e da concorrência de mercado.

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
Demais pastas (`config/`, `database/`, `tests/`...): a definir.

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
- Nomenclatura de variáveis e funções: a definir.
- Estrutura de endpoints: cada página é uma rota autenticada ligada a um componente Livewire (ex.: `/contingencias` → `Livewire\Contingencias`); login em `/entrar`, logout em `POST /sair`; exceções em controller: `SairController` e `LandingFiscalController` (`/landing/fiscal`).
- Estrutura de componentes: telas usam só os componentes `<x-ui.*>` (faltou um, cria-se em `components/ui`); cores só por tokens do tema; ícones Material Symbols via `<x-ui.icone>`; fonte Roboto servida localmente; gráficos `<x-grafico.colunas|linhas|barras>` dentro de `<x-grafico.cartao>`, sempre com uma tabela dos mesmos números.
- Tipagem: a definir.

## TDD
- Framework backend: Pest
- Framework frontend: a definir
- Onde ficam os testes: a definir
- Regra: cobertura mínima a definir; antes de concluir uma tarefa, Pint, PHPStan e Pest precisam passar.
- Testes críticos deste projeto:
  - [ ] Ao receber o código 656 (consumo indevido) da Sefaz, a consulta para na hora e não consulta mais nada.
  - [ ] Segredos do sactus.cfg (senha, token) saem mascarados (`ArquivoCfg::mascarar`) e só aparecem reais quando um admin pede.
  - [ ] `Auditoria::registrar()` registra usuário, ação, cliente e IP e nunca grava senha ou valor sensível em `detalhes`.

## Nunca fazer
- Nunca renomear tabela ou coluna vinda do banco legado `suporte` (só acrescentar colunas e tabelas novas).
- Nunca escrever no banco do Help Desk: a conexão `help` é somente leitura.
- Nunca instalar PHP, Composer ou Node no Windows — tudo roda nos contêineres Docker.
- Nunca gravar senha ou valor sensível em `detalhes` da `Auditoria`.
- Nunca escrever botões, campos ou tabelas soltos com classes Tailwind repetidas, nem usar hexadecimal solto na tela — só `<x-ui.*>` e tokens do tema.

## Decisões em aberto
- [ ] Modelo de dados completo (migrations novas do walle vs. tabelas legadas herdadas) e contrato de cada Job de fila (`ExecutarAcaoSactus`, `ProcessarClienteSactus`, `RodarCicloAutorizadas`, `RodarCicloSactus`).
- [ ] Critérios de autorização além do `Gate::allows('admin')` (perfis, permissões granulares) e integração completa com o squad ExpxAgents que gera `analise-concorrentes.json`.
