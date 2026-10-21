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
pelo Laravel Boost (`docker exec walle-app php artisan boost:update`). O MCP do Boost roda com
`docker exec -i walle-app php artisan boost:mcp` (já configurado em `.mcp.json` e `.codex/config.toml`).

O sistema antigo continua no contêiner `testes220` (http://localhost:8074) como referência.
