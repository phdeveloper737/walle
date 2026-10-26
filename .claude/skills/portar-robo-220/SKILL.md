---
name: portar-robo-220
description: "Porta um robô (script cíclico) do newsuporte/220 para o walle no mesmo padrão do RoboAutorizadas e do RoboSactus: classe de domínio com ligado() e rodarCiclo(), Job único na conexão robos, robô desligado por padrão (WALLE_*_ATIVO=false), falha de um cliente que não derruba o ciclo, Help Desk somente leitura e auditoria das operações abrangidas, inclusive automáticas e testes Pest com servidores falsos (nunca reais). Use quando a tarefa pedir para substituir um script do 220 (sentinela.php, cleantax.php, *.worker.php, *.crawler.php, task.scheduler.bot.php...), criar um robô, um ciclo, um Job da fila robos. Não use para a tela ou painel do robô (use tela-walle-ui) nem para criar a tabela em que o robô grava (use portar-tabela-220)."
---

# Portar um robô do 220 para o walle

O walle substitui os scripts que rodam em loop no servidor 220 (`newsuporte`). Cada robô portado precisa:

- devolver **os mesmos resultados com os mesmos dados**;
- nascer **desligado**;
- **nunca encostar em nada real durante os testes**: servidor de cliente, Help Desk, Sefaz ou API externa.

Esta skill detalha `.ai/guidelines/walle.md`, que prevalece sobre seus exemplos. Os requisitos aprovados em 24/10/2026 podem exigir adequações do código existente; não confunda documentação com implementação.

## 1. Antes de escrever código

1. **Leia o script original.** Ele está em `C:\docker\testes220\newsuporte` (o contêiner `testes220` roda em http://localhost:8074). Para achar: `grep -rl "sentinela.php" C:\docker\testes220\newsuporte\system`.
2. **Anote o que o original faz:**
   - quais clientes ele pega e com qual filtro (`executar_bot_* = 'S'`, `deletado = 'N'`...);
   - o que chama em cada cliente, com quais timeouts;
   - em quais tabelas e colunas grava, e com quais valores (inclusive os textos de erro);
   - o que faz quando um cliente falha.
3. **Leia os robôs irmãos.** São a referência de forma: `app/Sactus/RoboSactus.php`, `app/Autorizadas/RoboAutorizadas.php`, `app/Jobs/RodarCicloSactus.php` e `app/Support/PausaDeRobo.php`.
4. **Leia a sua task no `PLAN.md`.** Ela diz quais arquivos você pode criar e o que fica para outra task (agenda, config, tela).

## 2. Regras que não se negociam

- **Nasce desligado.** `ligado()` devolve `(bool) config('walle.<robo>.ativo')`, e o `.env` usa `WALLE_<ROBO>_ATIVO=false`. Desligado, nem o Job nem as ações manuais do robô fazem nada.
  - Se a task proíbe mexer em `config/` (Fase 3 do PLAN), leia a chave mesmo assim: `null` vira `false`, ou seja, desligado. Nos testes, ligue com `config(['walle.<robo>.ativo' => true])`. A chave e o `.env.example` entram na task de agenda (5.1).
- **Testes nunca saem da máquina.**
  - HTTP: `Http::preventStrayRequests()` + `Http::fake([...])`.
  - Banco do cliente: `servidorDoClienteNoBancoDeTestes()` ou `servidorDoClienteForaDoAr()`.
  - SSH: `sshFalso()`.
  - Sefaz: `sefazFalsa()`.
  - Todos esses helpers estão em `tests/Pest.php`. Nada de `sleep` real nos testes.
- **Um cliente com problema não derruba o ciclo.** Envolva cada cliente em `try/catch (Throwable)` com `report($e)`, grave o erro na linha do cliente (`mb_substr(..., 0, 500)`) e siga para o próximo.
- **Nunca dois robôs no mesmo cliente.** Quando há "rodar agora" ou ação manual, use `Cache::lock('<robo>:cliente:'.$id, <segundos>)`. Se não conseguir a trava, devolva "ocupado".
- **Help Desk somente leitura por qualquer conexão.** Não criar ou usar `EscritaHelpDesk`/`help_escrita` para gravar no Help Desk. Sincronização de VPN, abertura de atendimentos e demais escritas nesse banco estão fora do escopo, mesmo para robôs e admins. Fixtures só em banco isolado de teste. Se o script do 220 grava no Help Desk e a task mantiver essa função, grave em tabela própria do banco `walle`, nunca no 192.168.10.254.
- **Auditoria humana e automática.** Ações humanas operacionais no painel, consultas à Sefaz e toda escrita externa permitida, inclusive de robôs, passam por `Auditoria::registrar()`. Identificar o ator humano ou a execução automática, ação, cliente e IP quando disponível; nunca senha, token, certificado ou SQL com valores sensíveis. O histórico do ciclo também é obrigatório e não substitui a auditoria dessas operações. Registrar cada operação uma vez na camada responsável.
- **Retorno 656 da Sefaz:** interromper imediatamente apenas o ciclo que recebeu o código, sem novas consultas nele. Outros ciclos e processos continuam; não criar bloqueio global por certificado nem exigir liberação manual por esse retorno.
- **Mesma escrita do 220 no escopo mantido.** Mesmas tabelas, colunas e valores, exceto desvios aprovados; nunca reproduzir escrita no Help Desk. Quando a tabela tem `UNIQUE` em `fk_c_cliente`, grave como o 220: `INSERT ... ON DUPLICATE KEY UPDATE` (veja `RoboSactus::gravar()`). Toda diferença proposital vai no docblock da classe, numa linha "Diferenças:", como no `RoboSactus`.
- **Parâmetros verificáveis.** Antes de implementar, transcreva do 220 os valores exatos de frequência, timeout, limite de falhas e duração de quarentena, com unidade e referência no contrato da tarefa. Se não houver valor inequívoco, peça decisão do usuário; não invente um padrão. Limites, timeouts e listas ficam em `config('walle.<robo>.<chave>', <padrão>)` ou em constante da classe com nome claro.
- **Escopo.** Não mexa em `routes/console.php`, `config/horizon.php`, menu ou rotas, a não ser que a task mande: isso é da task de agenda ou de telas.

## 3. Arquivos e forma

| O quê | Onde | Forma |
|---|---|---|
| Robô | `app/<Dominio>/Robo<Nome>.php` | `ligado(): bool`, `rodarCiclo(): <Ciclo ou void>`; dependências pelo construtor (`private readonly`) |
| Resultado de um cliente | `app/<Dominio>/Resultado<Coisa>.php` | `final readonly class` com propriedades públicas |
| Job | `app/Jobs/RodarCiclo<Nome>.php` | igual ao `RodarCicloSactus` (abaixo) |
| Pausa (se o robô tiver) | `new PausaDeRobo('<robo>:pausa')` | `pausa()`, `pausar()`, `retomar()` |
| Testes | `tests/Feature/<Dominio>/Robo<Nome>Test.php` | Pest, nomes de teste em português |

O Job segue o `app/Jobs/RodarCicloSactus.php`:

```php
class RodarCicloSentinela implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $timeout = 3600;

    public int $tries = 1;

    public int $uniqueFor = 3600;

    public function __construct()
    {
        $this->onConnection('robos');
        $this->onQueue('sentinela');
    }

    public function handle(RoboSentinela $robo): void
    {
        if (! $robo->ligado()) {
            return;
        }

        $robo->rodarCiclo();
    }
}
```

O ciclo segue este esqueleto:

```php
public function rodarCiclo(): void
{
    foreach ($this->clientes() as $cliente) {
        try {
            $this->gravar($cliente->id, $this->coletar($cliente));
        } catch (Throwable $e) {
            report($e);
            $this->gravar($cliente->id, ['status' => 'N', 'mensagem' => mb_substr($e->getMessage(), 0, 500)]);
        }
    }
}
```

## 4. Testes (escreva antes; confirme que falham)

Cubra pelo menos:

1. **Caminho feliz com dado do 220.** Use uma resposta real capturada no `testes220` (JSON, linhas). Confira que a linha gravada é igual à que o 220 gravaria: mesmas colunas, mesmos valores.
2. **Cliente com falha** (timeout, JSON inválido, `FalhaConexaoCliente`, `FalhaSsh`). O erro fica registrado nele, e o próximo cliente é processado.
3. **Robô desligado.** `app(RodarCicloX::class)->handle(app(RoboX::class))` não grava nada e não faz nenhuma chamada: `Http::assertNothingSent()` ou o falso sem chamadas.
4. **Regra específica do 220 no escopo mantido** (quarentena, um pedido por host...). Nenhuma execução grava no Help Desk; conferir auditoria e histórico próprio das operações automáticas abrangidas.

Cuidados com os testes:

- **Hora fixa:** `$this->travelTo(Carbon::parse('2026-10-21 10:00:00'))`.
- **Funções de apoio em arquivo de teste são globais.** Antes de criar `function nomeX()`, rode `grep -rn "function nomeX" tests/`; nome repetido derruba a suíte inteira com "Cannot redeclare". Prefira nomes específicos do domínio (`painelSentinelaDe()`).
- **Banco de testes.** Durante o desenvolvimento, rode os seus arquivos; antes de concluir código, rode toda a suíte. Se outros agentes trabalham em paralelo, use o seu banco: `docker exec -e DB_DATABASE=walle_testes_<seu> walle-app ./vendor/bin/pest tests/Feature/<Dominio>/Robo<Nome>Test.php`.

## 5. Antes de dizer que terminou

```powershell
docker exec walle-app ./vendor/bin/pest   # toda a suíte antes de concluir
docker exec walle-app ./vendor/bin/pint <seus arquivos>          # --dirty só se ninguém mais estiver mexendo no projeto
docker exec walle-app ./vendor/bin/phpstan analyse   # configuração completa do projeto
```

No relatório, diga:

- o que o 220 faz e que o walle passou a fazer igual;
- as diferenças propositais;
- o que ficou para outra task (agenda, chave no `.env.example`, tela).

## 6. Exemplo

**Input (prompt da task):**
> Task 3.2: Bot Sentinela (substitui `sentinela.php`). Output: `app/Sentinela/RoboSentinela.php` (`ligado()`, `rodarCiclo()`), que faz `GET http://<host>/sentinela.php?json=S` em cada cliente e grava em `painel_sentinela`; `app/Sentinela/ColetaSentinela.php`; `app/Jobs/RodarCicloSentinela.php`. Testes: o painel fica igual ao do 220 para o mesmo JSON; timeout ou JSON inválido grava "sem resposta" sem derrubar o ciclo.

**Output esperado:**
- `app/Sentinela/RoboSentinela.php`:
  - lê os clientes como o `sentinela.php`;
  - faz `Http::timeout(<o mesmo do 220>)->get(...)`;
  - grava com `ON DUPLICATE KEY UPDATE` em `painel_sentinela`;
  - cada cliente fica em `try/catch`;
  - o docblock traz "substitui o sentinela.php" e as diferenças.
- `app/Sentinela/ColetaSentinela.php` como `final readonly class`, com os campos lidos do JSON.
- `app/Jobs/RodarCicloSentinela.php` no padrão acima (`robos`, fila `sentinela`, único).
- `tests/Feature/Sentinela/RoboSentinelaTest.php`:
  - `Http::fake` com o JSON real capturado no `testes220`, comparando a linha gravada coluna a coluna;
  - um cliente com `Http::response(null, 500)` ou `ConnectionException`, gravado como "sem resposta", com o próximo cliente processado;
  - robô desligado sem nenhuma requisição.
- Nenhuma mudança em `routes/console.php`, `config/` ou telas. O relatório avisa que `WALLE_SENTINELA_ATIVO` e a agenda ficam para a task 5.1.

## 7. Quando NÃO usar

- **Tela, aba ou painel que mostra o robô, ou botão "rodar agora":** use `tela-walle-ui`. Esta skill cobre só a classe que o botão chama.
- **Criar ou importar a tabela em que o robô grava:** use `portar-tabela-220`.
- **Ligar o robô em produção ou registrar a agenda (`routes/console.php`, Horizon),** quando a task não pede: isso é decisão da virada, com o usuário.
- **Consulta pontual ou ação única** (ex.: executar uma query num cliente, converter certificado): não é robô. Basta uma classe de domínio comum.
- **Script do 220 que só lê e mostra dados, sem ciclo:** é tela.
