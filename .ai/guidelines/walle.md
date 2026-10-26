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
- Classificação Mercadológica (decisão de 26/10/2026): o robô grava (`INSERT`, `UPDATE` e `DELETE`) nos bancos dos clientes, então fica fora de toda automação. Nenhum agente, comando, agenda ou fluxo automático implementa, agenda ou liga essa funcionalidade sem pedido explícito do usuário; ela é a última do plano (seção manual do `PLAN.md`).
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

- Um branch por tarefa e PR no GitHub. Fluxo automático (decisão do usuário de 26/10/2026): a sessão principal coordena, os agentes implementam, o `walle-arquiteto` revisa o PR e publica o parecer como comentário, e o merge acontece sem intervenção quando o CI está verde e não há achado BLOQUEANTE ou IMPORTANTE pendente. Nunca usar a opção `--admin` no merge (o hook barra). Exceção: a Classificação Mercadológica, que segue manual.
- Antes de concluir qualquer alteração de código, o agente executa Pint nos PHP alterados, PHPStan com a configuração completa do projeto e **toda a suíte Pest** (`./vendor/bin/pest`, que inclui Unit, Feature e Migrations pelo `phpunit.xml`), dentro do Docker, e informa os resultados. Testes filtrados são intermediários; não substituem a suíte completa nem sua execução pelo agente. Se um check falhar ou não puder rodar, relate a pendência e não declare a validação concluída. Edição exclusivamente documental exige revisão de consistência e sincronização, sem executar ferramentas de código.
- A validação completa é o gate: `powershell -NoProfile -ExecutionPolicy Bypass -File .claude/hooks/gate.ps1` (com `-Banco walle_testes_tNN` em sessão paralela). Ele roda Pint em modo teste, PHPStan, a suíte Pest inteira e os testes das políticas, do gate, do plugin do OpenCode e do RAG, e grava a evidência em `.walle/gate/ultimo.json` (código de cada etapa, commit e hash da árvore de arquivos). Só vale como evidência o gate com `valida_para_commit: true` (completo, verde e com a árvore igual à do commit: primeiro o commit, depois o gate); a prova em ambiente limpo é o CI, que roda as mesmas etapas. Em sessão paralela o gate usa o banco da sessão e é preliminar; o definitivo roda sozinho, no gate da fase.
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
