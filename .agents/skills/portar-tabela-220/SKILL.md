---
name: portar-tabela-220
description: "Traz uma tabela do banco suporte do 220 para o banco walle sem mudar nada: lê o SHOW CREATE TABLE pela conexão suporte (só leitura), escreve a migration com nomes, tipos, NULL, DEFAULT, índices, FKs e ON UPDATE idênticos (utf8mb4_general_ci), o model com o nome da tabela e o teste que fixa a estrutura. Quando a task pede, inclui a tabela no walle:importar-suporte na ordem das FKs. Para em vez de improvisar quando o schema do 220 contradiz o teste pedido. Use quando a tarefa pedir migration ou model de uma tabela que existe no 220 (painel_*, *_log, informacao_tecnica_*, task_scheduler_*, history_*...) ou pedir para incluir uma tabela no importador. Não use para tabela nova do walle sem equivalente no 220, para tabelas do Help Desk (192.168.10.254, que o walle só lê, ao vivo) nem para alterar uma tabela legada que já existe no walle."
---

# Portar uma tabela do 220 para o banco `walle`

Regra do projeto: a tabela que vem do 220 mantém **exatamente** o nome de tabela e de coluna (`fk_c_cliente`, `painel_sentinela`...). Nunca renomeie nem "melhore". Acrescentar coluna ou tabela nova pode; mudar a que veio, não. O resultado precisa aceitar os dados do 220 como estão, porque o `walle:importar-suporte` copia linha a linha.

A fonte central `.ai/guidelines/walle.md` prevalece sobre esta skill. O Help Desk é somente leitura por qualquer conexão; fixtures são permitidas apenas em banco isolado de teste. Regras de paridade ressalvam os desvios já aprovados.

## 1. Ler o schema do 220 (somente leitura)

A conexão `suporte` aponta para o MySQL do `testes220`. Use só `SHOW`/`SELECT` nela, nunca escrita.

```powershell
docker exec walle-app php artisan tinker --execute 'echo DB::connection("suporte")->selectOne("SHOW CREATE TABLE painel_sentinela")->{"Create Table"};'
```

Para tirar dúvida de NULL ou DEFAULT, consulte o `information_schema.columns`. Para entender o uso real, procure o `CREATE TABLE`/`INSERT` no código do 220, em `C:\docker\testes220\newsuporte`.

## 2. Migration

- Nome: `database/migrations/<AAAA_MM_DD_HHMMSS>_create_<tabela>_table.php`. A do filho vem depois da do pai, por causa das FKs.
- Docblock curto: "<tabela>: mesma estrutura do banco suporte." e para que ela serve (veja `2026_10_21_210001_create_painel_sactus_cfg_table.php`).
- Declare `$table->charset('utf8mb4'); $table->collation('utf8mb4_general_ci');`. Assim a tabela não depende do `DB_COLLATION` do `.env`.
- Mantenha **a mesma ordem de colunas** do 220.

| No `SHOW CREATE TABLE` | Na migration |
|---|---|
| `id int NOT NULL AUTO_INCREMENT` + `PRIMARY KEY (id)` | `$table->integer('id', true);` |
| `bigint ... AUTO_INCREMENT` | `$table->bigInteger('id', true);` (unsigned: `unsignedBigInteger('id', true)`) |
| `int NOT NULL` (sem DEFAULT) | `$table->integer('col');` |
| `int DEFAULT NULL` | `$table->integer('col')->nullable();` |
| `varchar(100)` / `char(1)` | `$table->string('col', 100)` / `$table->char('col', 1)` |
| `text` / `longtext` | `$table->text('col')` / `$table->longText('col')` |
| `decimal(10,3)` | `$table->decimal('col', 10, 3)` |
| `tinyint` / `smallint` | `$table->tinyInteger('col')` / `$table->smallInteger('col')` |
| `enum('S','N')` | `$table->enum('col', ['S', 'N'])` |
| `datetime` / `date` | `$table->dateTime('col')` / `$table->date('col')` |
| `DEFAULT 'X'` / `DEFAULT ''` | `->default('X')` / `->default('')` |
| `DEFAULT CURRENT_TIMESTAMP` | `->useCurrent()` |
| `... ON UPDATE CURRENT_TIMESTAMP` | `->useCurrent()->useCurrentOnUpdate()` |
| `COMMENT '...'` | `->comment('...')` |
| `UNIQUE KEY uq_cliente (fk_c_cliente)` | `$table->unique('fk_c_cliente', 'uq_cliente');` (**mesmo nome de índice**) |
| `KEY idx_data (data)` | `$table->index('data', 'idx_data');` |
| `FOREIGN KEY ... ON DELETE CASCADE` | `$table->foreign('fk_x', '<nome>')->references('id')->on('<pai>')->cascadeOnDelete();` (sem `ON DELETE` é `NO ACTION`, que no InnoDB bloqueia como `RESTRICT`) |
| FK para tabela do Help Desk (`a_user`, `c_cliente`...) | essas tabelas não existem no banco `walle`: crie só o índice com o mesmo nome, explique no docblock (precedente: `a_user_acessos`) e cite como desvio no relatório |
| Tabela sem `PRIMARY KEY` | não invente `id`; no model, `$primaryKey = null` e `$incrementing = false` |

Diferenças de formatação que **não** são divergência (não "corrija"):
- `DEFAULT (now())` aparece como `DEFAULT CURRENT_TIMESTAMP`;
- `USING BTREE` some porque é o padrão do InnoDB;
- o 220 (MySQL 8.0) mostra `CHARACTER SET utf8mb4` em cada coluna e o walle (MySQL 9.7) só a `COLLATE`.

## 3. Model

- Nome da classe = nome da tabela em StudlyCase, como o PLAN.md define (`painel_cleantax_worker` → `PainelCleantaxWorker`, `task_scheduler_querys_log` → `TaskSchedulerQueryLog`). Fica em `app/Models/`.
- `protected $table = '<tabela>';` sempre.
- `public $timestamps = false;` quando a tabela não tem `created_at` e `updated_at` (quase sempre).
- **Tabela sem `id`** (só `UNIQUE` em `fk_c_cliente`): `protected $primaryKey = 'fk_c_cliente'; public $incrementing = false;`.
- `$guarded = ['id']` quando há `id` auto-incremento; senão `$guarded = []`. Use `[]` também quando o 220 grava o `id` explicitamente (ex.: `informacao_tecnica_painel_contingencias`, em que `id = fk_c_cliente`): com o `Model::shouldBeStrict()` ligado fora de produção, proteger o `id` lança exceção.
- Coluna com senha ou segredo (`senha_ssh`, `senha_mysql`...): ponha em `$hidden` e avise no relatório que a tela precisa mascarar.
- `@property` de cada coluna no docblock, como em `Auditoria` e `PainelSactusCfg`.
- Casts:
  - `datetime` para colunas de data;
  - `decimal:N` quando a tela precisar.
  - Para JSON gravado em texto, `array` serve para **ler**. Quem grava (robô) deve gravar a string com os mesmos flags do 220 (ex.: `JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES`), para a paridade não quebrar.
- Relações só as que a task pede, pelas colunas do 220: `hasMany(Log::class, 'fk_c_cliente', 'fk_c_cliente')`. Nunca chame uma relação de `query()`, `save()`, `update()` ou outro método do Eloquent: o nome colide. Use o nome do model relacionado (`taskSchedulerQuery()`).

## 4. Teste (escreva antes; confirme que falha)

Fica em `tests/Migrations/<Nome>Test.php`. Essa pasta também é lida como pasta de migrations de teste, e o Migrator pega `*_*.php`; por isso **o nome do arquivo não pode ter `_`**. O `tests/Pest.php` só aplica o TestCase em `Feature`, então declare-o no arquivo (já no formato que o Pint deixa):

```php
<?php

use App\Models\PainelSentinela;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

uses(TestCase::class);

test('a tabela painel_sentinela tem as colunas do 220', function () {
    $colunas = collect(Schema::getColumns('painel_sentinela'))
        ->mapWithKeys(fn (array $c): array => [$c['name'] => $c['type'].($c['nullable'] ? ' null' : '')])
        ->all();

    expect($colunas)->toBe([
        'id' => 'int',
        'fk_c_cliente' => 'int',
        // ... todas, na ordem do 220 (lista fixada aqui, nunca lida do 220 durante o teste)
    ]);
});

test('gravar sem fk_c_cliente falha com QueryException', function () {
    expect(fn () => PainelSentinela::create(['data' => now()]))->toThrow(QueryException::class, 'fk_c_cliente');
});
```

O que testar:
- **Estrutura:** lista de colunas e tipos fixada no teste. Para os índices que importam, use `Schema::getIndexes()` e confira nome, colunas e `unique`.
- **Ida e volta:** o model grava e lê uma linha (casts e defaults do 220).
- **A regra que o teste crítico pede** (NOT NULL, UNIQUE, FK com cascade/restrict, ON UPDATE), provada com `QueryException` ou com a mudança de valor.
- **`ON UPDATE CURRENT_TIMESTAMP`:** os testes rodam em transação, e o relógio do MySQL não anda sozinho no mesmo segundo. Grave um valor antigo explícito (`'2020-01-01 00:00:00'`), faça `UPDATE` de outra coluna e confira que mudou. Sem `sleep`.

Rode só o seu arquivo. Com outros agentes em paralelo, use o seu banco de testes:

```powershell
docker exec -e DB_DATABASE=walle_testes_<seu> walle-app ./vendor/bin/pest tests/Migrations/<Nome>Test.php
```

## 5. Quando o 220 contradiz o teste pedido: pare

Exemplo real (task 1.4): o PLAN.md pedia `QueryException` ao gravar sem `fk_c_cliente`, mas no 220 a coluna é `int DEFAULT NULL`.

- **Não** torne a coluna obrigatória por conta própria e **não** enfraqueça o teste.
- Pare e relate:
  - o trecho do `SHOW CREATE TABLE`;
  - o que o código do 220 faz;
  - se os dados reais têm o caso (`SELECT COUNT(*) ... WHERE col IS NULL`);
  - as opções: fiel ao 220, ou desvio aprovado.
- Desvio aprovado pelo usuário vira **uma linha de comentário na migration** com o motivo.

## 6. Importador (só se a task pedir)

`app/Console/Commands/ImportarSuporte.php`:
- **`TABELAS`:** a tabela entra em `TABELAS` na ordem das FKs (pai antes do filho). A lista também é a lista branca: tabela fora dela não é importada.
- **Tabela sem `id`:** precisa de uma coluna de ordem estável em `ORDEM` (ex.: `'history_contingencia' => 'chave'`).
- **Teste:** importar só a tabela nova copia as linhas, e rodar de novo não duplica.

## 7. Antes de dizer que terminou

```powershell
docker exec walle-app ./vendor/bin/pint <seus arquivos>
docker exec walle-app ./vendor/bin/phpstan analyse <models e migrations>   # tests/ fica fora do phpstan.neon (o $this do Pest dá falso positivo)
```

Antes de concluir alterações de código, o agente também executa `docker exec walle-app ./vendor/bin/phpstan analyse` com a configuração completa e `docker exec walle-app ./vendor/bin/pest` sem filtros. Os comandos restritos acima são verificações intermediárias.

No relatório, inclua:
- a comparação do `SHOW CREATE TABLE` do 220 com o do seu banco de testes, ignorando `AUTO_INCREMENT` e as diferenças de formatação da seção 2;
- o RED e o GREEN;
- os desvios, se houver.

## 8. Exemplo

**Input (prompt da task):**
> Task 1.5: Tabelas do Task Scheduler (`task_scheduler_querys` e `task_scheduler_querys_log`). Output: duas migrations e os models `TaskSchedulerQuery` e `TaskSchedulerQueryLog` (`belongsTo` do log para a query). Testes: colunas iguais às do 220 e o log aponta para a query certa; apagar uma query com logs segue a regra da FK do 220.

**Output esperado:**
- `database/migrations/2026_10_23_150001_create_task_scheduler_querys_table.php` e `..._150002_create_task_scheduler_querys_log_table.php`: colunas na ordem do 220, collation explícita e a FK com o mesmo nome e a mesma regra de `ON DELETE`.
- `app/Models/TaskSchedulerQuery.php` (`hasMany` só se a task pedir) e `app/Models/TaskSchedulerQueryLog.php` com `taskSchedulerQuery(): BelongsTo` pela coluna de FK do 220. O log não tem PK no 220, então leva `$primaryKey = null`.
- A FK de `fk_a_user` para `a_user` (Help Desk) vira só índice com o mesmo nome, citada como desvio.
- `tests/Migrations/TabelasTaskSchedulerTest.php` com `uses(TestCase::class)`:
  - colunas fixadas;
  - o log volta para a query certa;
  - apagar a query com log lança `QueryException` (restrict) ou apaga o log (cascade), conforme o 220.
- **Se o 220 não tiver FK entre as duas,** não invente uma: pare e relate (seção 5).

## 9. Quando NÃO usar

- **Tabela nova do walle**, que não existe no 220 (ex.: `auditoria`, `sactus_ciclos`): escreva a migration normal do Laravel, com as convenções do projeto; não há o que copiar.
- **Tabela do Help Desk** (`c_cliente`, `a_user`, `s_atendimento`... no 192.168.10.254): o walle só lê pela conexão `help`, com model `SomenteLeitura`. Ela não vira migration no banco `walle` (a não ser a imitação para teste em `tests/Migrations/HelpDesk/`): o walle consulta ao vivo e nunca copia essas tabelas para o banco `walle`, nem pelo `walle:importar-suporte`.
- **Alterar tabela legada que já está no walle:** só migration de acréscimo (coluna ou tabela nova), nunca renomear nem mudar tipo. Isso é outra conversa com o usuário.
- **Robô que grava na tabela:** use `portar-robo-220`. **Tela que lê a tabela:** use `tela-walle-ui`.
