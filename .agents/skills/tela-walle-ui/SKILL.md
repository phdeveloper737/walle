---
name: tela-walle-ui
description: "Cria ou altera telas internas Livewire 4 do walle com componentes Blade walle-ui, tokens do tema, abas e filtros por URL, gráficos acessíveis e estados de erro ou vazio. Cobre ações de admin com Gate, ConfirmaSenha e Auditoria, além de testes Pest das telas. Use para painéis, relatórios, formulários e ações em app/Livewire ou resources/views/livewire. Para robôs e Jobs, use portar-robo-220; para migrations, portar-tabela-220. Não use para alterar o design system nem para landing ou marketing da JM, que seguem frontend-design."
---

# Tela no padrão walle-ui

Toda tela do walle é um componente Livewire 4 com view Blade montada só com os componentes do walle-ui (`resources/views/components/ui`) e os gráficos de `resources/views/components/grafico`. Os textos da tela são em português do Brasil. A fonte central `.ai/guidelines/walle.md` prevalece sobre esta skill; as decisões documentadas não atestam adequação do código existente.

Antes de começar, leia:
- a tela do 220 que você está substituindo, em `C:\docker\testes220\newsuporte` (no ar em http://localhost:8074);
- uma tela irmã parecida:
  - com abas e robô: `app/Livewire/Sactus.php` e `app/Livewire/Sactus/Robo.php`;
  - com gráficos: `resources/views/livewire/autorizadas/analise.blade.php`.

## 1. Componente

- Fica em `app/Livewire/<Nome>.php` (subtelas em `app/Livewire/<Nome>/<Aba>.php`), com `#[Title('...')]` e docblock "substitui o X do sistema antigo".
- **Abas** seguem o padrão do `Concorrentes`: aba inválida na URL volta para a padrão já ao abrir a tela.

```php
public const ABAS = [
    'clientes' => ['rotulo' => 'Clientes', 'icone' => 'groups'],
    'motivos' => ['rotulo' => 'Motivos', 'icone' => 'bar_chart'],
];

#[Url(except: 'clientes')]
public string $aba = 'clientes';

public function mount(): void
{
    $this->updatedAba();
}

public function updatedAba(): void
{
    if (! array_key_exists($this->aba, self::ABAS)) {
        $this->aba = 'clientes';
    }
}
```

- Na view: `<x-ui.abas :abas="\App\Livewire\<Nome>::ABAS" :atual="$aba" />` e `@switch($aba)` com `<livewire:<nome>.<aba> key="aba-<aba>" />` (ou um `@include` simples quando a aba não tem estado próprio).
- **Filtros** que precisam sobreviver a um F5 usam `#[Url]`. Por exemplo, o `?id=` do 220 vira `#[Url] public ?int $cliente = null;`. Valor que não existe mostra o estado vazio, nunca erro 500.
- **Consultas** ficam em `#[Computed]`, com `@property-read` no docblock da classe para o PHPStan. Nunca em propriedade pública: o navegador não carrega a lista inteira.
- **Help Desk fora do ar.** Consulta na conexão `help` (192.168.10.254) vai em `try/catch (QueryException)` com `report($e)`. A tela mostra `<x-ui.aviso tipo="erro">` e continua de pé (veja `Sactus\Robo::plano()`).
- **Dados do Help Desk só leitura:** models com o trait `App\Models\Concerns\SomenteLeitura`.

## 2. View: só walle-ui

| Precisa de | Use | Props / variações |
|---|---|---|
| Botão | `<x-ui.botao>` | `variante`: `preenchido` (padrão), `tonal`, `contorno`, `texto`; `icone`, `href`, `type` |
| Botão só com ícone | `<x-ui.botao-icone>` | `icone`, `rotulo` (obrigatório: vira `title` e `aria-label`) |
| Campo de formulário | `<x-ui.campo>` | `label`, `tipo`, `icone`, `ajuda`, `id` + `wire:model` |
| Busca | `<x-ui.busca>` | `placeholder` + `wire:model.live.debounce` |
| Filtro de opções | `<x-ui.segmentado>` / `<x-ui.chip>` | `opcoes`, `compacto` / `ativo`, `contagem` |
| Mensagem | `<x-ui.aviso>` | `tipo`: `info` (padrão), `erro`, `sucesso` |
| Situação | `<x-ui.etiqueta>` | `cor`: `neutra`, `primaria`, `secundaria`, `erro`, `sucesso` |
| Número em destaque | `<x-ui.kpi>` | `rotulo`, `valor`, `icone`, `detalhe` |
| Bloco | `<x-ui.card>` | `titulo`, `icone`, slot `acoes` |
| Nada para mostrar | `<x-ui.vazio>` | `icone`, `titulo` + texto no slot |
| Paginação | `<x-ui.paginacao>` | `pagina`, `totalPaginas`, `total`, `porPagina`, `acao` |
| Ícone | `<x-ui.icone nome="..."/>` | nome do Material Symbols (`dns`, `smart_toy`, `history`...) |
| Senha do admin | `<x-ui.confirmar-senha :aberta="$pedindoSenha" />` | vem junto com o trait `ConfirmaSenha` |

Regras de visual:

- **Cores** só por token do tema: `bg-surface`, `bg-surface-container-low`/`-high`/`-highest`, `text-on-surface`, `text-on-surface-variant`, `bg-primary`, `border-outline-variant`... Nunca hexadecimal, `bg-red-500` ou `text-gray-*`.
- **Tabela nova:** depende do componente `<x-ui.tabela>`, a ser criado em tarefa própria (PLAN task 6.0) antes das novas telas dependentes. Use as tabelas existentes como referência de tokens, números alinhados, `wire:key`, cabeçalhos com `scope` e rolagem horizontal ao projetar o componente. Não copie tabelas HTML soltas para novas telas.
- **Tabela existente:** preserve a marcação atual até tarefa explícita de migração para o componente. `<x-grafico.tabela>` continua sendo a tabela dos gráficos.
- **Faltou um componente?** Ele é criado em `resources/views/components/ui` como task própria, não escondido dentro da sua tela.

### Textos da tela

- **O botão diz o que acontece:** "Remover cliente", "Salvar configuração", "Rodar agora". Nunca "Confirmar", "Enviar" ou "OK".
- **O mesmo verbo vai até o aviso:** "Remover cliente" → "Cliente removido."
- **Nomes pelo que a equipe entende,** não pelo que está no banco: "Sem resposta", e não `status_execucao = N`.
- **Erro diz o que houve e o que fazer,** sem pedir desculpas. Exemplo: "O Help Desk (192.168.10.254) não respondeu. Os números abaixo são da última coleta."
- **Estado vazio explica por quê** ou o que fazer. Exemplo: "Nenhum ciclo ainda. O histórico começa no primeiro ciclo que rodar."
- **Só a primeira letra maiúscula.** Sem rótulo em MAIÚSCULAS e sem numeração (01, 02...) quando o conteúdo não é uma sequência.

### Acessibilidade e movimento

- **Foco de teclado:** `<x-ui.botao>`, `<x-ui.botao-icone>` e `<x-ui.segmentado>` já mostram foco (`focus-visible`). Elemento clicável escrito à mão (ex.: item de menu num `<button>`) precisa do mesmo tratamento. Nunca use `outline-none` sem pôr outro destaque de foco no lugar.
- **Rótulos para leitor de tela:** `<x-ui.botao-icone>` sempre com `rotulo`, e cabeçalho de tabela com `<th scope="col">`.
- **Animação** só como resposta a uma ação (abrir, expandir, confirmar), com `motion-reduce:` quando você criar alguma. Nada de seções entrando animadas.
- **Tela estreita:** tabela larga dentro de `overflow-x-auto`; grades com `sm:`/`lg:`/`2xl:`, como nas telas atuais.

## 3. Gráficos

```blade
<x-grafico.cartao titulo="Contingências por motivo" descricao="Semana de segunda a domingo.">
    <x-grafico.barras :itens="$itens" :series="[['nome' => 'Contingências', 'cor' => 'grafico-1', 'valores' => $valores]]" />
    <x-slot:tabela>
        <x-grafico.tabela :colunas="['Motivo', 'Contingências']" :linhas="$linhas" />
    </x-slot:tabela>
</x-grafico.cartao>
```

- Sem biblioteca JS. Use `<x-grafico.colunas>`, `<x-grafico.linhas>` ou `<x-grafico.barras>`, sempre dentro de `<x-grafico.cartao>`.
- **Todo gráfico tem a tabela com os mesmos números** (`<x-slot:tabela>`). A dica do mouse nunca é o único jeito de ler um valor.
- **Cores:** só `grafico-1`, `grafico-2` e `grafico-contexto`, sobre `surface-container-low`.
- **Legenda:** com duas séries ou mais, vai a `:legenda` no cartão. Com uma série, não há legenda e o título diz o que é. Nunca dois eixos Y.
- **Filtro de período:** numa linha acima de tudo o que ele recorta, nunca dentro de um cartão.

## 4. Ação de admin (mexe fora do walle, mostra segredo ou altera dado)

```php
use ConfirmaSenha;

public const ACOES_COM_SENHA = ['salvar', 'remover'];

// Sem injeção nos parâmetros: depois da senha o trait chama o método direto, só com os argumentos guardados
public function remover(int $clienteId): void
{
    if (! $this->adminConfirmado('remover', [$clienteId])) {
        return;
    }

    $apagadas = app(RemocaoDeCliente::class)->remover($clienteId);
    Auditoria::registrar('contingencias.remover_cliente', $clienteId, ['linhas' => $apagadas]);
    $this->avisar("{$apagadas} vendas removidas.");
}
```

- A confirmação é reutilizável na sessão por `config('walle.confirmar_senha_minutos')` minutos a partir de `walle.senha_confirmada_em`; expirou, pede senha novamente. Não exigir nova digitação em cada ação durante essa janela. O Gate continua obrigatório em cada ação.
- `adminConfirmado()` já faz `abort_unless(Gate::allows('admin'), 403)`, e quem não é admin recebe 403. Na view, esconda o botão de quem não é admin com `'admin' => Gate::allows('admin')`. Esconder é só conforto: a proteção é o 403.
- O método vai em `ACOES_COM_SENHA` e **não recebe dependência injetada nos parâmetros**. Use `app(Classe::class)` dentro dele.
- `Auditoria::registrar('<tela>.<acao>', $clienteId, [...])` em toda ação. Em `detalhes`, **nunca** vai senha, token, conteúdo de certificado ou valor de segredo.
- SQL manual em cliente, inclusive `SELECT`, exige admin e confirmação válida antes da conexão. Help Desk nunca recebe escrita, por qualquer conexão.
- Conversor de certificado: recebimento, conversão e entrega inteiramente em memória; o upload temporário convencional do Livewire não atende. Definir transporte sem arquivos temporários ou persistência em servidor web/PHP, disco, sessão, cache, fila ou log antes de implementar.
- **Ação que depende de robô desligado ou ligado:** confira `ligado()` antes e avise com `<x-ui.aviso tipo="erro">`, como em `Sactus\Robo::rodarAgora()`.
- **Ação perigosa:** use `wire:confirm="..."` no botão. Com `wire:poll`, desligue o poll enquanto a senha é pedida: `@if (! $pedindoSenha) wire:poll.15s @endif`.

## 5. Rotas e menu

Só se a task mandar (no PLAN.md isso é das Fases 7 e 11). A rota vai em `routes/web.php`, dentro do grupo `auth`: `Route::livewire('/<rota>', <Nome>::class)->name('<rota>');`. O item de menu vai em `resources/views/layouts/app.blade.php` com `<x-ui.menu-item>`. O menu Programação só aparece para admin.

## 6. Testes (escreva antes; confirme que falham)

```php
test('admin remove o cliente depois de confirmar a senha e fica na auditoria', function () {
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);
    $cliente = criarClienteHelpDesk();
    criarContingenciaVersatus($cliente);

    Livewire::test(Contingencias::class)
        ->call('remover', $cliente)
        ->assertSet('pedindoSenha', true)
        ->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha');

    expect(Auditoria::where('acao', 'contingencias.remover_cliente')->count())->toBe(1);
});
```

Cubra pelo menos:
- a tela (cada aba) abre com `200` e mostra os dados semeados (use os helpers de `tests/Pest.php`: `criarClienteHelpDesk()`, `criarContingenciaVersatus()`...);
- sem dados aparece o texto do `<x-ui.vazio>`; aba ou filtro inválido na URL não quebra;
- ação de admin: quem não é admin recebe `403` (`->assertForbidden()` ou `->assertStatus(403)`); admin sem senha só abre a janela; com senha, executa e registra Auditoria sem segredo;
- números da tela batem com o 220 para os mesmos dados, quando a task pede paridade.

Cuidados:
- **Admin:** `config(['walle.admins' => [$id]])` no próprio teste. Não crie `function tornarAdmin()`: ela já existe em `PainelSactusTest.php`, e funções em arquivo de teste são globais (nome repetido derruba a suíte com "Cannot redeclare").
- **Senha:** teste confirmação válida reutilizada e confirmação expirada que pede senha novamente. Para SQL manual, cubra também SELECT recusado sem admin ou confirmação. A senha padrão do usuário de teste é `segredo`. Para pular a janela da senha, use `session()->put('walle.senha_confirmada_em', now()->getTimestamp())`.
- **Banco de testes:** se outros agentes trabalham em paralelo, rode com o seu banco: `docker exec -e DB_DATABASE=walle_testes_<seu> walle-app ./vendor/bin/pest <seus testes>`.

## 7. Antes de dizer que terminou

```powershell
docker exec walle-app ./vendor/bin/pest   # toda a suíte antes de concluir
docker exec walle-app ./vendor/bin/pint <seus arquivos>
docker exec walle-app ./vendor/bin/phpstan analyse   # configuração completa do projeto
docker run --rm -v C:/docker/walle:/app -w /app node:24-alpine npm run build   # se usou classe Tailwind nova
```

Depois, abra a tela em http://localhost:8080/<rota> logado e confira:
- tema claro e escuro;
- tela estreita;
- estado vazio;
- Help Desk fora do ar.

Se não deu para abrir no navegador, diga isso no relatório.

## 8. Exemplo

**Input (prompt da task):**
> Task 6.3: Painel do Sentinela (substitui `sentinela.dash.php`). Output: `app/Livewire/Sentinela.php` (filtro `#[Url] $cliente`, como o `?id=` do 220) e `resources/views/livewire/sentinela.blade.php`. Testes: a tela mostra cada cliente com o estado coletado e filtra pelo cliente da URL; cliente inexistente na URL mostra o vazio, sem erro.

**Output esperado:**
- `app/Livewire/Sentinela.php`:
  - `#[Title('Sentinela')]` e docblock "substitui o sentinela.dash.php";
  - `#[Url] public ?int $cliente = null;`;
  - `#[Computed] linhas()` lendo `PainelSentinela` e juntando o nome do cliente pelo Help Desk, em `try/catch (QueryException)`.
- `resources/views/livewire/sentinela.blade.php`:
  - linha de KPIs com `<x-ui.kpi>` (total, respondendo, sem resposta);
  - tabela com `<x-ui.tabela>` (pré-requisito: task 6.0), com a situação em `<x-ui.etiqueta cor="sucesso|erro">`;
  - `<x-ui.vazio icone="sensors_off" titulo="Nenhum cliente encontrado">` quando o filtro não acha nada.
- `tests/Feature/Sentinela/PainelSentinelaTest.php`: três testes (lista, filtro pela URL, cliente inexistente mostra o vazio).
- A tela nova com tabela depende da task 6.0. Rota e menu ficam de fora, porque são da task 7.1. O relatório avisa isso.

## 9. Quando NÃO usar

- **Classe de robô, Job, ciclo ou alerta:** use `portar-robo-220`. Esta skill cobre só a tela que mostra e controla o robô.
- **Migration ou model de tabela do 220:** use `portar-tabela-220`.
- **Mudar o design system** (tokens em `app.css`, cores `grafico-*`, componentes `<x-ui.*>` existentes): é decisão de design, com validação de contraste e daltonismo. Peça antes.
- **Endpoint JSON/API ou controller tradicional** (ex.: `SairController`, `LandingFiscalController`): não é tela Livewire.
- **Landing ou página de marketing da JM** (`resources/views/marketing/`): use `/frontend-design`, com a identidade da marca JM. O contrário também vale: **não** use a `frontend-design` numa tela interna, porque ela manda criar paleta e fonte próprias, o que o walle-ui proíbe.
