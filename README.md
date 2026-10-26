# walle

Reescrita do sistema **newsuporte** (servidor 220) com tecnologia atual, aberta e gratuita.

| Peça | Versão |
|---|---|
| PHP | 8.5 (FPM) |
| Laravel | 13 |
| Livewire | 4 (com Alpine) |
| Tailwind CSS | 4 + **walle-ui** (Material Design 3, componentes em `resources/views/components/ui`) |
| MySQL | 9.7 LTS — banco `walle` |
| Valkey | 9 (compatível com Redis): sessão, cache e filas |
| Horizon / Scheduler | filas e agendador do Laravel |
| Pest · PHPStan (Larastan) · Pint | testes, análise estática e formatação |

Login: usuários do **Help Desk** (`a_user` do 192.168.10.254), com a mesma regra do sistema antigo.
Tabelas que vêm do banco `suporte` mantêm exatamente o mesmo nome de tabela e de coluna.

## Rodar no seu PC (Docker Desktop)

```powershell
cd C:\docker\walle
docker compose up -d
```

| Endereço | O quê |
|---|---|
| http://localhost:8080 | walle |
| http://localhost:8080/saude | saúde do sistema (banco, Valkey, Help Desk, filas, agendador) |
| http://localhost:8080/horizon | painel das filas |
| `localhost:3309` | MySQL do walle (usuário e senha no `.env`) |

Front em desenvolvimento, com recarga automática: `docker compose --profile dev up -d` (Vite em `localhost:5173`).
Sem isso, depois de mudar CSS/JS rode o build:

```powershell
docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build
```

## Comandos do dia a dia

Tudo roda dentro do contêiner (não é preciso PHP, Composer ou Node no Windows):

```powershell
docker exec walle-app php artisan migrate
docker exec walle-app composer require pacote/nome
docker exec walle-app ./vendor/bin/pest            # testes
docker exec walle-app ./vendor/bin/pint            # formatação
docker exec walle-app ./vendor/bin/phpstan analyse # análise estática
docker exec walle-app php artisan optimize:clear   # depois de mudar .env ou config/
```

## Contêineres

| Contêiner | O que faz |
|---|---|
| `walle-app` | PHP 8.5-FPM (Laravel) |
| `walle-web` | nginx na porta 8080 |
| `walle-mysql` | MySQL 9.7 (volume `walle_mysql`) |
| `walle-valkey` | Valkey 9 (volume `walle_valkey`) |
| `walle-horizon` | processa as filas |
| `walle-scheduler` | roda o agendador a cada minuto |
| `walle-vite` | Vite (só com `--profile dev`) |

## Para quem programa (Claude Code e Codex)

As regras do projeto ficam em `.ai/guidelines/walle.md` e entram no `CLAUDE.md` e no `AGENTS.md`
pelo Laravel Boost (`docker exec walle-app php artisan boost:update`). Essa é a fonte central: SPEC, PLAN e skills devem segui-la. Após regenerar, conferir a precedência das regras centrais sobre as orientações genéricas do Boost. Skills e referências de `.claude/skills/` e `.agents/skills/` devem permanecer equivalentes. O MCP do Boost roda com
`docker exec -i walle-app php artisan boost:mcp` (já configurado em `.mcp.json` e `.codex/config.toml`).

O sistema antigo continua no contêiner `testes220` (http://localhost:8074) como referência.

## Requisitos consolidados em 24/10/2026

- Help Desk (`192.168.10.254`) sempre somente leitura por qualquer conexão, agora e no futuro. Toda persistência necessária fica exclusivamente no banco local `walle`, sem repassar gravações ao Help Desk.
- Auditoria cobre ações humanas do painel, consultas à Sefaz e escritas externas permitidas, incluindo robôs; ciclos também têm histórico próprio.
- SQL manual em clientes, inclusive SELECT, só para admin com confirmação válida na sessão. A confirmação vale pela janela configurada em `walle.confirmar_senha_minutos`; verificar o Gate em cada ação.
- Código 656 interrompe apenas o ciclo que o recebeu; outros ciclos e processos continuam.
- Conversão de certificados inteiramente em memória, desde o recebimento, sem arquivos temporários ou persistência do conteúdo.
- Criar componente de tabela em tarefa própria antes das novas telas com tabela; manter as atuais até migração explícita.
- O agente executa Pint nos PHP alterados, PHPStan completo e toda a suíte Pest antes de concluir alterações de código. Revisão só documental exige consistência e sincronização.

Estes são requisitos documentados. A revisão não modifica a aplicação nem afirma que o código existente já atende às decisões.

## Memória local dos agentes (RAG)

O RAG do Claude Code captura aprendizados em `SubagentStop` (agente) e `Stop` (síntese da sessão,
ao fim de cada resposta), em segundo plano. Em `UserPromptSubmit`, devolve até três trechos
semanticamente relevantes, com preferência configurável pela categoria do agente ativo.
Os hooks de proteção anteriores continuam ativos. Reabra a sessão do Claude Code depois de
alterar a configuração de hooks; este registro não instala hooks equivalentes no Codex.

```powershell
docker compose -f .claude/scripts/compose.yml up -d --build
docker exec walle-rag node -e "fetch('http://127.0.0.1:7391/health').then(r=>r.json()).then(console.log)"
```

Aguarde `ready: true`. O primeiro uso baixa os pesos públicos de `Xenova/all-MiniLM-L6-v2` para
um volume Docker; textos e perguntas não são enviados a APIs. O contêiner mantém o modelo
carregado, não publica porta no host e só recebe dados pelo wrapper `.claude/hooks/rag.ps1`.
O resumo é **extrativo**, sem modelo pago: seleciona declarações das mensagens dos assistentes,
nas quatro categorias solicitadas, e omite segredos/blocos de código. Não verifica sozinho a
veracidade de uma declaração. Uma nota curta pode gerar chunk menor que 200 tokens; as demais
são divididas com o tokenizer do modelo em até 250 tokens, com sobreposição.
Os pesos estão fixados por revisão; mudar a revisão do modelo faz as notas serem reindexadas,
evitando misturar vetores de versões diferentes.

As notas e o SQLite/sqlite-vec ficam em `.claude/knowledge/`, ignorados pelo Git. O arquivo
`rag.config.json` em `.claude/scripts/` define o bônus de categoria, o limiar de similaridade e
o mapeamento de agentes; após alterá-lo, reconstrua e recrie o serviço com o comando acima.
Consulte a saúde do serviço para ver quantidade de documentos/chunks
e eventuais pendências de indexação. Sem o serviço/modelo, a sessão continua sem injeção de
memória; os Markdown já salvos são reindexados na próxima inicialização.

```powershell
docker compose -f .claude/scripts/compose.yml run --rm --no-deps rag npm test
docker compose -f .claude/scripts/compose.yml run --rm --no-deps rag npm run typecheck
docker compose -f .claude/scripts/compose.yml run --rm --no-deps rag npm run smoke
powershell -NoProfile -ExecutionPolicy Bypass -File .claude/scripts/tests/hook.test.ps1
```

Os testes de scripts rodam no Docker, com SQLite e diretórios temporários isolados; o último
comando verifica somente o adaptador nativo Windows. O `smoke` usa o modelo real e verifica
captura, busca e recuperação do serviço após reinício ou falha de reindexação.
`summarize.ts` aceita texto/JSONL ou JSON com `context`/`messages` pela entrada padrão;
`embed.ts` recebe o caminho de um Markdown em `/knowledge`; `search.ts` recebe JSON com
`question` e `agent` pela entrada padrão. Os hooks usam `worker.ts` para evitar recarregar o
modelo a cada chamada. Não há limpeza automática nem upload das memórias.
