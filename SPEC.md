# SPEC — walle

> Documento de especificação consolidado a partir do `README.md`, `CLAUDE.md`/`.ai/guidelines/walle.md`
> (regras do projeto para Claude Code e Codex) e leitura da estrutura atual do código (`app/`, `routes/`).
> Não há memórias de sessões anteriores registradas para este projeto — este arquivo é a primeira
> consolidação da documentação existente.

> Fonte normativa: `.ai/guidelines/walle.md`. Esta especificação segue as decisões aprovadas em 24/10/2026; a revisão documental não atesta implementação no código existente. O plano cobre apenas as funcionalidades mantidas, excluindo toda escrita no Help Desk.

## 1. Visão geral

O **walle** é a reescrita do sistema legado **newsuporte** (servidor 220) com stack atual, aberta e
gratuita. É a aplicação interna usada pela equipe para acompanhar e operar processos fiscais e de
infraestrutura dos clientes (contingência de NFC-e, autorizações na Sefaz, servidores Sactus) e para
dar visibilidade de saúde do próprio sistema e de concorrência de mercado.

Login é feito com as credenciais do **Help Desk** (`a_user`, banco `suporte` em 192.168.10.254),
seguindo a mesma regra de autenticação do sistema antigo.

## 2. Stack técnica

| Peça | Versão |
|---|---|
| PHP | 8.5 (FPM) |
| Laravel | 13 |
| Livewire | 4 (com Alpine) |
| Tailwind CSS | 4 + **walle-ui** (Material Design 3, componentes em `resources/views/components/ui`) |
| MySQL | 9.7 LTS — banco `walle` |
| Valkey | 9 (compatível com Redis) — sessão, cache e filas |
| Horizon / Scheduler | filas e agendador do Laravel |
| Pest · PHPStan (Larastan) · Pint | testes, análise estática e formatação |

Tudo roda em contêineres Docker — não se instala PHP, Composer ou Node no Windows.

## 3. Ambiente e contêineres

| Contêiner | Função |
|---|---|
| `walle-app` | PHP 8.5-FPM (Laravel) |
| `walle-web` | nginx — `http://localhost:8080` |
| `walle-mysql` | MySQL 9.7, banco `walle` (volume `walle_mysql`) — Windows: `localhost:3309` |
| `walle-valkey` | Valkey 9 — sessão, cache e filas (volume `walle_valkey`) |
| `walle-horizon` | processa as filas (`php artisan horizon`) |
| `walle-scheduler` | roda o agendador a cada minuto (`php artisan schedule:work`) |
| `walle-vite` | Vite em desenvolvimento, só com `--profile dev` (`localhost:5173`) |

O sistema antigo (`newsuporte`) permanece disponível em `testes220`
(`http://localhost:8074`, código em `C:\docker\testes220\newsuporte`) e serve de referência: o walle
deve devolver os mesmos resultados com os mesmos dados para as funcionalidades mantidas no escopo, ressalvados os desvios explicitamente aprovados.

Subir localmente:

```powershell
cd C:\docker\walle
docker compose up -d
```

Endereços úteis: `/` (painel), `/saude` (saúde do sistema), `/horizon` (filas).

Comandos do dia a dia (sempre dentro do contêiner):

```powershell
docker exec walle-app php artisan migrate
docker exec walle-app composer require pacote/nome
docker exec walle-app ./vendor/bin/pest
docker exec walle-app ./vendor/bin/pint --dirty
docker exec walle-app ./vendor/bin/phpstan analyse
docker exec walle-app php artisan optimize:clear   # depois de mudar .env ou config/
docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build
```

O OPcache revalida arquivos a cada 2 s — uma edição pode levar até esse tempo para aparecer.

## 4. Banco de dados

- Tabelas vindas do banco legado `suporte` mantêm **exatamente** o mesmo nome de tabela e de coluna
  (`c_cliente`, `s_atendimento`, `fk_c_cliente`...). Nunca renomear; pode-se acrescentar colunas e
  tabelas novas.
- Models com nome em português apontando para a tabela legada: `protected $table = 'c_cliente';` e
  `public $timestamps = false;` quando a tabela não tiver `created_at`/`updated_at`.
- Collation padrão: `utf8mb4_general_ci` (igual às tabelas legadas, evita "Illegal mix of collations").
- Datas são hora local (`America/Sao_Paulo`), sem fuso.
- Conexão `help`: banco `suporte` do servidor 192.168.10.254 (Help Desk), **somente leitura** — é
  dali que vem o login (`a_user`). Usa o usuário `walle` do .254, que só tem `SELECT` no banco `suporte`; a senha vem só do
  `.env` (`HELP_DB_SENHA_RESERVA`) e a API do Help não é consultada (decisão de 25/10/2026). O walle consulta o Help Desk
  sempre ao vivo e não mantém cópia das tabelas dele no banco `walle`. Nenhuma conexão do walle pode escrever no Help Desk, inclusive uma eventual `help_escrita`. Sincronização de VPN, abertura de atendimentos, cadastro de versões, atualização de portas e de software nesse banco estão fora do escopo. Fixtures em bancos isolados de teste não são escritas no Help Desk real.
- Regra permanente (24/10/2026): o Help Desk em `192.168.10.254` permanece somente leitura agora e no futuro. Toda funcionalidade que precisar persistir dados deve gravar exclusivamente no banco local `walle`, inclusive dados complementares ou alterações associados a registros consultados no Help Desk; nunca repassar essas gravações ao Help Desk.

## 5. Módulos / funcionalidades (rotas autenticadas)

| Rota | Componente | O que faz |
|---|---|---|
| `/entrar` | `Livewire\Entrar` | Login com credenciais do Help Desk |
| `/` | `Livewire\Painel` | Painel inicial |
| `/contingencias` | `Livewire\Contingencias` | Lista de clientes (Help Desk) à esquerda e detalhe do cliente escolhido à direita — conecta no servidor do cliente e verifica o estado de contingência de NFC-e. Resultado fica em cache por 2 min para não bater no servidor do cliente a cada clique |
| `/autorizadas` | `Livewire\Autorizadas` | Robô que confere na Sefaz GO as contingências já autorizadas, análise da base e consulta de uma chave de acesso específica (substitui `autorizadas.dash`, `.analise` e `.debug` do sistema antigo) |
| `/sactus` | `Livewire\Sactus` | sactus.cfg coletado de cada cliente, verificador de flags, pedidos/ações e o robô (substitui `sactus.dash.php`) |
| `/concorrentes` | `Livewire\Concorrentes` | Análise competitiva da JM Sistemas (resumo, fichas, matriz comparativa, oportunidades) — somente leitura; conteúdo gerado pelo squad ExpxAgents "concorrentes-analise-competitiva" em `analise-concorrentes.json` |
| `/saude` | `Livewire\Saude` | Checagem em tempo real de cada peça do walle (app, MySQL, Valkey, Help Desk, Horizon, robôs) — tela com `wire:poll` |
| `/landing/fiscal` | `LandingFiscalController` | Landing page fiscal |
| `POST /sair` | `SairController` | Logout |

### 5.1 Autorizadas (`app/Autorizadas`)

Robô (`RoboAutorizadas`) que substitui `tools/services/autorizadas.php` do sistema antigo. Por ciclo:

1. Apaga de `history_contingencia_auth` as chaves com referência além da retenção (73 dias) e não as
   semeia de novo (diferença do antigo, que desperdiçava consultas refazendo isso).
2. Semeia as chaves novas de `history_contingencia` (marcadas como não autorizadas).
3. Consulta na Sefaz GO até 250 pendentes (nunca consultadas ou consultadas há mais de 3 h).
4. Grava autorizada S/N e a hora da consulta, de 50 em 50.

Mantém uma conexão TLS para o ciclo inteiro; se a Sefaz acusar consumo indevido (código 656), o robô
interrompe imediatamente apenas esse ciclo, sem novas consultas nele. Outros ciclos e processos continuam, sem bloqueio global por certificado ou liberação manual. Ciclo registrado em `CicloAutorizadas`. Também oferece diagnóstico de uma chave isolada
(`DiagnosticoChave`) e consulta por período (`Periodo`).

### 5.2 Contingências (`app/Contingencias`)

Conecta no servidor do cliente (`ConectorDeCliente` / `ConexaoComCliente`) para buscar o estado de
contingência (`BuscaContingencias`, `ResultadoContingencias`, `ItemContingencia`). Lista de clientes
vem do Help Desk (`ListaDeClientes`/`ClienteDaLista`). Falhas de conexão são tratadas via
`FalhaConexaoCliente`.

Decisão aprovada em 24/10/2026: o robô de contingências mantém histórico próprio exclusivamente
no banco walle, em `contingencias_ciclos` (`CicloContingencias`) e `contingencias_coletas`
(`ColetaContingencias`). Cada coleta identifica o ciclo e o cliente, com início, término, estado,
totais e eventual erro sanitizado. A última coleta é associada às métricas de
`painel_contingency_crawler`, sem alterar a estrutura legada: falhas mantêm a última métrica válida
e permitem identificar sua desatualização; sucesso posterior preserva o histórico de falhas.
Nenhuma FK aponta ao Help Desk, não há escrita nele e não se acrescenta retenção automática.
O robô nasce desligado; agenda, configuração operacional e tela são tarefas posteriores.

### 5.3 Sactus (`app/Sactus`)

Coleta e interpreta o `sactus.cfg` de cada cliente via SSH (`app/Sactus/Ssh`: `ConectorSsh`,
`ConectorPhpseclib`, `SessaoSsh`). Funcionalidades:

- `ArquivoCfg` — leitura/edição do cfg; segredos (senha, token) saem **mascarados**
  (`ArquivoCfg::mascarar`) e só aparecem reais quando um admin solicita explicitamente.
- `AcoesSactus` / `RotinaAcl` / `RotinaErp` / `Prechecagem` — ações e rotinas sobre o cliente.
- `RoboSactus` — robô cíclico (substitui `sactus.dash.php`); diferenças do antigo: o `yum -y update`
  passou a ser ação manual do painel, o ciclo é registrado em `sactus_ciclos` (`CicloSactus`) e um
  cliente nunca é processado por dois robôs ao mesmo tempo (ciclo e "aplicar agora" se excluem).
- `PainelSactus` / `PainelSactusCfg` — dados consolidados para a tela.
- Jobs assíncronos: `RodarCicloSactus`, `ProcessarClienteSactus`, `ExecutarAcaoSactus`.

### 5.4 Concorrentes (`app/Concorrentes`)

`AnaliseConcorrentes` apenas lê o arquivo `analise-concorrentes.json` gerado pelo squad ExpxAgents
"concorrentes-analise-competitiva" e exibe na tela (resumo, fichas, matriz comparativa e
oportunidades). Não há escrita a partir do walle.

### 5.5 Sefaz (`app/Sefaz`)

`ConsultaSituacao` consulta a situação de uma NFC-e (`SituacaoNfce`, `ChaveDeAcesso`,
`ResultadoConsulta`) usando certificado digital (`Certificado`, `SessaoSefazComCertificado`,
`SessaoSefaz`). Ao receber 656 (consumo indevido), interrompe imediatamente apenas o ciclo que recebeu o código. Não faz novas consultas nesse ciclo; outros ciclos e processos continuam. Não há bloqueio global por certificado nem liberação manual decorrente desse retorno.

### 5.6 Saúde (`app/Livewire/Saude.php`)

Confere, de fato, cada peça do walle (não apenas se o processo está de pé): aplicação, banco `walle`,
Valkey, Help Desk, Horizon, robôs. Tela se atualiza sozinha via `wire:poll`.

### 5.7 Auditoria (`app/Models/Auditoria.php`)

Tabela nova do walle (`auditoria`) para identificar operações. Ações humanas executadas no painel, consultas à Sefaz e toda escrita externa permitida, inclusive de robôs, passam por `Auditoria::registrar()`. Registrar ator humano ou identificação explícita da execução automática, ação, cliente e IP quando disponível. Navegação e renderização não são ações operacionais. Ciclos também mantêm histórico próprio; esse histórico não substitui a auditoria das operações abrangidas. Nunca registrar senha, token, certificado ou SQL com valores sensíveis. Auditoria não autoriza escrita no Help Desk.

### 5.8 Memória local dos agentes (RAG)

O suporte ao desenvolvimento usa `.claude/scripts/summarize.ts`, `embed.ts` e `search.ts`, no
contêiner independente `walle-rag`. `SubagentStop` captura o transcript do agente e `Stop` atualiza
a síntese da sessão inteira ao fim da resposta; ambos são assíncronos. `UserPromptSubmit` busca
até três chunks pertinentes e os injeta como contexto histórico, sem alterar o prompt original.
As proteções existentes dos hooks permanecem ativas.

Por escolha do usuário, o resumo é extrativo e local, sem chamadas a modelos pagos: seleciona
aprendizados declarados nas mensagens textuais dos assistentes. Não inclui mensagens do usuário,
thinking ou resultados brutos de ferramentas. As categorias são bugs resolvidos com causa raiz,
decisões de arquitetura e o porquê, padrões do time e tentativas que não funcionaram. Segredos e
blocos de código são omitidos antes da persistência. Isso não comprova por si só a veracidade da
declaração: memórias não substituem as fontes normativas, código, aprovação ou validação atual.

As notas ficam em `.claude/knowledge/AAAA-MM-DD-agent-slug.md`, sem versionamento automático.
O SQLite `.claude/knowledge/rag.db` mantém `knowledge` com id, path, content, embedding, category,
agent e created_at, e um índice `sqlite-vec`. O modelo local `Xenova/all-MiniLM-L6-v2` gera vetores
normalizados de 384 dimensões; o download inicial dos pesos fica em volume Docker. Chunks usam
o tokenizer do modelo, com 200–250 tokens e sobreposição; notas curtas podem ter menos de 200.
O teto de 250 evita truncar o conteúdo ao acrescentar os tokens especiais do modelo.

`.claude/scripts/rag.config.json` define o mapeamento agente/categoria, o limiar de similaridade
e o bônus de categoria. A preferência não inclui notas abaixo do limiar. Prompts não são salvos.
Capturas repetidas são idempotentes e reindexações substituem os chunks em transação. O serviço
não publica porta no host, não monta o projeto ou credenciais e não acessa os bancos da aplicação.
Se o serviço ou modelo estiver indisponível, o hook não bloqueia o trabalho nem inventa contexto.
A leitura de transcripts é limitada ao projeto e conserva até 120 mil caracteres de mensagens
textuais dos assistentes; não é um arquivo integral da conversa nem um mecanismo de backup.

## 6. Segurança e controle de acesso

- Help Desk somente leitura em duas camadas: no banco, o usuário `walle` só tem `SELECT`; na aplicação,
  `TravaHelpDeskProducao` deixa passar só `SELECT`, `SHOW`, `DESCRIBE` e `EXPLAIN` na conexão `help` e no host de
  produção, e registra cada tentativa barrada na auditoria (`help_desk.escrita_barrada`), sem SQL nem valores.
- Ações de admin exigem `Gate::allows('admin')` (ids configurados em `WALLE_ADMINS`).
- No Livewire, o trait `ConfirmaSenha` (`app/Livewire/Concerns/ConfirmaSenha.php`) pede a senha do
  Help Desk de novo antes de executar a ação; o método precisa estar listado em `ACOES_COM_SENHA` e
  não deve receber dependências injetadas nos parâmetros (o trait chama o método direto após
  confirmar a senha). A confirmação é reutilizada na sessão por `config('walle.confirmar_senha_minutos')` minutos a partir de `walle.senha_confirmada_em`. Expirada, pede senha novamente; o Gate é conferido em cada ação.
- Robôs que agem fora do walle (Autorizadas, Sactus) podem ser desligados via `.env`
  (`WALLE_*_ATIVO`); com o robô desligado, as ações manuais equivalentes também não rodam.
- Auditoria segue o §5.7, incluindo operações automáticas; não se limita a ações humanas.
- SQL manual em servidor de cliente, inclusive `SELECT`, exige admin com confirmação válida na sessão antes da conexão. Jobs agendados seguem a autorização da tarefa cadastrada, com auditoria e robô ligado.
- Conversor de certificados: receber PFX e senha, converter e entregar o resultado inteiramente em memória. Não gravar payload nem temporários em disco, sessão, cache, fila ou log. Garantir esse requisito em toda a cadeia de entrada, inclusive servidor web e PHP; o upload temporário convencional do Livewire não atende. O transporte deve ser definido antes da implementação.

## 7. Front-end (walle-ui)

- Visual Material Design 3 com Tailwind 4 e componentes Blade próprios em
  `resources/views/components/ui/`.
- Telas internas usam `<x-ui.*>` e gráficos `<x-grafico.*>`, sem duplicar botões, campos e tabelas com classes Tailwind. Componente ausente entra em tarefa própria antes da tela dependente. Criar `<x-ui.tabela>` antes das novas telas com tabela; telas existentes ficam até tarefa explícita de migração. Tabelas de gráficos continuam usando `<x-grafico.tabela>`.
- Cores só pelos tokens do tema (`bg-surface`, `text-on-surface`, `bg-primary`...), nunca hexadecimal
  solto na tela.
- Ícones: Material Symbols via `<x-ui.icone nome="..."/>`. Fonte Roboto. Tudo servido localmente, sem
  CDN.
- Interatividade via Livewire 4 (+ Alpine).

### Gráficos (`resources/views/components/grafico/`)

- Sem biblioteca JS: `<x-grafico.colunas>`, `<x-grafico.linhas>`, `<x-grafico.barras>` são HTML/SVG
  gerados no servidor dentro de `<x-grafico.cartao>`.
- Todo gráfico tem uma tabela com os mesmos números (`<x-slot:tabela>` + `<x-grafico.tabela>`) — a
  dica do mouse nunca é a única forma de ler um valor.
- Cores restritas a `grafico-1`, `grafico-2` e `grafico-contexto` (tokens definidos em `@theme static`
  no `app.css`), sempre sobre `surface-container-low`; validadas para daltonismo/contraste — trocar
  exige validar de novo.
- Duas ou mais séries: legenda no cartão. Uma série: sem legenda, o título diz o que é. Nunca dois
  eixos Y no mesmo gráfico.
- Filtro de período fica numa linha acima do que ele recorta, nunca dentro de um cartão.

## 8. Qualidade e fluxo de trabalho

- Um branch por tarefa, PR no GitHub, em fluxo automático (decisão do usuário de 26/10/2026): os agentes implementam, o `walle-arquiteto` revisa o PR e o merge acontece quando o CI está verde e não há achado BLOQUEANTE ou IMPORTANTE pendente. A Classificação Mercadológica segue manual.
- Para concluir alterações de código, o agente executa Pint nos PHP alterados, PHPStan com a configuração completa e toda a suíte Pest no Docker. Testes filtrados são intermediários; não delegar a suíte ao usuário. Falha ou impedimento deve ser relatado como pendência. Alterações exclusivamente documentais exigem revisão de consistência e sincronização.
- Regras completas do projeto (fonte): `.ai/guidelines/walle.md`, replicadas em `CLAUDE.md` e
  `AGENTS.md` pelo Laravel Boost (`docker exec walle-app php artisan boost:update`).
- MCP do Boost: `docker exec -i walle-app php artisan boost:mcp` (configurado em `.mcp.json` e
  `.codex/config.toml`).

## 9. Lacunas conhecidas deste documento

Este SPEC foi reconstruído a partir da documentação disponível no repositório (não existiam memórias
de sessões anteriores registradas para o walle). Pontos que podem merecer detalhamento futuro, se o
time quiser:

- Modelo de dados completo (migrations novas do walle vs. tabelas legadas herdadas).
- Parâmetros operacionais ainda não transcritos (frequência, timeout, limite de falhas e quarentena): extrair os valores exatos do 220, registrar unidades e referência no contrato da tarefa; se não houver valor inequívoco, solicitar decisão antes de implementar. Não escolher números a partir de termos como "folgado" ou "N falhas".
- Contrato detalhado de cada Job de fila (`ExecutarAcaoSactus`, `ProcessarClienteSactus`,
  `RodarCicloAutorizadas`, `RodarCicloSactus`).
- Critérios de autorização além do `Gate::allows('admin')` (perfis, permissões granulares).
- Integração completa com o squad ExpxAgents que gera `analise-concorrentes.json`.
