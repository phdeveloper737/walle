# walle — regras do projeto

O walle é a reescrita do sistema "newsuporte" (servidor 220) em Laravel. Responda e escreva textos de tela em português do Brasil.

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
- Formatação: `docker exec walle-app ./vendor/bin/pint --dirty`
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
- Conexão `help`: banco `suporte` do servidor 192.168.10.254 (Help Desk), **somente leitura**. É de lá que vem o login (`a_user`).

## Ações que mexem fora do walle

- Tudo que escreve no servidor de um cliente, consulta a Sefaz ou mostra um segredo passa por `Auditoria::registrar()` (quem, o quê, cliente, IP). Nunca grave senha ou valor sensível em `detalhes`.
- Ações de admin: `Gate::allows('admin')` (ids em `WALLE_ADMINS`) e, no Livewire, o trait `ConfirmaSenha` (pede a senha do Help Desk de novo). Liste o método em `ACOES_COM_SENHA` e não use injeção de dependência nos parâmetros dele (depois da senha o trait chama o método direto).
- Robôs que agem fora do walle ficam desligados por `.env` (`WALLE_*_ATIVO`) até a virada; com o robô desligado, as ações manuais dele também não rodam.
- Segredos do `sactus.cfg` (senha, token...) saem mascarados do servidor (`ArquivoCfg::mascarar`); o valor real só vai para a tela quando um admin pede.

## Front-end (walle-ui)

- Visual Material Design 3 implementado com Tailwind 4 e componentes Blade próprios em `resources/views/components/ui/`.
- Telas usam só os componentes `<x-ui.*>`; não escreva botões, campos ou tabelas soltos com classes Tailwind repetidas. Faltou componente, crie um em `components/ui`.
- Cores só pelos tokens do tema (`bg-surface`, `text-on-surface`, `bg-primary`...), nunca hexadecimal solto na tela.
- Ícones: Material Symbols (`<x-ui.icone nome="..."/>`). Fonte: Roboto. Tudo servido localmente, sem CDN.
- Interatividade com Livewire 4 (e Alpine, que vem junto).

### Gráficos (`components/grafico/`)

- Sem biblioteca de JS: `<x-grafico.colunas>`, `<x-grafico.linhas>` e `<x-grafico.barras>` são HTML/SVG gerados no servidor, dentro de `<x-grafico.cartao>`.
- Todo gráfico tem a tabela com os mesmos números (`<x-slot:tabela>` com `<x-grafico.tabela>`): a dica do mouse nunca é o único jeito de ler um valor.
- Cores só `grafico-1`, `grafico-2` e `grafico-contexto` (definidas em `@theme static` no `app.css`), sempre sobre o `surface-container-low`. Elas foram validadas para daltonismo e contraste nesse fundo; trocar cor exige validar de novo.
- Duas séries ou mais: legenda no cartão. Uma série: sem legenda, o título diz o que é. Nunca dois eixos Y no mesmo gráfico.
- O filtro de período fica numa linha acima de tudo o que ele recorta, nunca dentro de um cartão.

## Fluxo de trabalho

- Um branch por tarefa e PR no GitHub. O Claude implementa, o Codex revisa e o usuário aprova.
- Antes de concluir: Pint, PHPStan e Pest passando.
- O sistema antigo roda no contêiner `testes220` (http://localhost:8074, código em `C:\docker\testes220\newsuporte`) e serve de referência: o walle deve devolver os mesmos resultados com os mesmos dados.

## Testes (TDD)

- Framework: Pest. Roda com `docker exec walle-app ./vendor/bin/pest` (filtrar: `--filter=nome`).
- Testes críticos deste projeto:
  - Ao receber o código 656 (consumo indevido) da Sefaz, a consulta para na hora e não consulta mais nada.
  - Segredos do `sactus.cfg` (senha, token) saem mascarados (`ArquivoCfg::mascarar`) e só aparecem reais quando um admin pede.
  - `Auditoria::registrar()` registra usuário, ação, cliente e IP e nunca grava senha ou valor sensível em `detalhes`.

## Decisões em aberto

- Modelo de dados completo (migrations novas do walle vs. tabelas legadas herdadas) e contrato de cada Job de fila (`ExecutarAcaoSactus`, `ProcessarClienteSactus`, `RodarCicloAutorizadas`, `RodarCicloSactus`).
- Critérios de autorização além do `Gate::allows('admin')` (perfis, permissões granulares) e integração completa com o squad ExpxAgents que gera `analise-concorrentes.json`.
