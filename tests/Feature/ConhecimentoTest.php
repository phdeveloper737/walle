<?php

use App\Livewire\Conhecimento;
use App\Models\Usuario;
use App\Rag\IndiceConhecimento;
use App\Rag\MapaConhecimento;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

function indiceRagDeTeste(): string
{
    $arquivo = tempnam(sys_get_temp_dir(), 'rag-teste-');
    $banco = DB::build(['driver' => 'sqlite', 'database' => $arquivo, 'foreign_key_constraints' => false]);
    $banco->statement('CREATE TABLE knowledge (id INTEGER PRIMARY KEY, path TEXT, content TEXT, embedding BLOB, category TEXT, agent TEXT, created_at TEXT)');
    $banco->statement('CREATE TABLE documents (path TEXT PRIMARY KEY, indexed_at TEXT)');
    foreach ([[1, 'Memória sobre arquitetura', 'arquitetura', 'walle-arquiteto', [1, 0]], [2, 'Correção documentada', 'bugs_resolvidos', 'walle-robos', [1, 0]], [3, 'Outra convenção', 'padroes', 'walle-dados', [0, 1]]] as [$id, $conteudo, $categoria, $agente, $vetor]) {
        $banco->insert('INSERT INTO knowledge VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, "memoria-$id.md", $conteudo, pack('g*', ...array_pad($vetor, 384, 0)), $categoria, $agente, '2026-10-27 00:31:11']);
        $banco->insert('INSERT INTO documents VALUES (?, ?)', ["memoria-$id.md", '2026-10-27 00:31:11']);
    }
    $banco->disconnect();
    app()->instance(IndiceConhecimento::class, new IndiceConhecimento($arquivo));

    return $arquivo;
}

test('o cérebro exige autenticação e admin', function () {
    $this->get('/conhecimento')->assertRedirect(route('entrar'));
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
    config(['walle.admins' => []]);
    $this->get('/conhecimento')->assertForbidden();
});

test('admin explora memórias e conexões reais com filtros e seleção', function () {
    $arquivo = indiceRagDeTeste();
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        $this->get('/conhecimento')->assertOk()->assertSee('Cérebro do RAG');
        $tela = Livewire::test(Conhecimento::class)
            ->assertSee(['Memória sobre arquitetura', 'Correção documentada', '100,0%'])
            ->set('memoria', 1)->assertSee('memoria-1.md')
            ->set('agente', 'walle-dados')->assertSee('Outra convenção')->assertDontSee('Correção documentada')
            ->set('agente', '')->set('categoria', 'bugs_resolvidos')->assertSee('Correção documentada')->assertDontSee('Outra convenção')
            ->set('categoria', '')->set('busca', 'arquitetura')->assertSee('Memória sobre arquitetura')->assertDontSee('Correção documentada')
            ->set('busca', 'MEMÓRIA')->assertSee('Memória sobre arquitetura')->assertDontSee('Correção documentada')
            ->set('busca', 'inexistente')->assertSee('Nenhuma memória encontrada')
            ->set('busca', '')->set('memoria', 99999)->assertSee('Selecione uma memória');
        config(['walle.admins' => []]);
        $tela->set('busca', '')->assertForbidden();
    } finally {
        unlink($arquivo);
    }
});

test('índice ausente mostra orientação sem criar banco', function () {
    $arquivo = sys_get_temp_dir().'/rag-ausente-'.uniqid().'.db';
    app()->instance(IndiceConhecimento::class, new IndiceConhecimento($arquivo));
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    $this->get('/conhecimento')->assertOk()->assertSee('O índice do RAG não está disponível');
    expect(file_exists($arquivo))->toBeFalse();
});

test('o índice calcula cosseno e preserva o banco somente leitura', function () {
    $arquivo = indiceRagDeTeste();
    try {
        $indice = app(IndiceConhecimento::class);
        $dados = $indice->carregar();
        expect($dados['total'])->toBe(3)->and($dados['documentos'])->toBe(3);
        $mapa = $indice->mapa();
        expect($mapa['conexoes'])->toHaveCount(1);
        expect($mapa['conexoes'][0])->toMatchArray(['origem' => 1, 'destino' => 2, 'similaridade' => 1.0]);
        expect(fn () => $indice->conexao()->delete('DELETE FROM knowledge'))->toThrow(QueryException::class, 'readonly');
    } finally {
        unlink($arquivo);
    }
});

test('leitura acompanha WAL e escapa memória sem executar HTML', function () {
    $arquivo = indiceRagDeTeste();
    $escrita = DB::build(['driver' => 'sqlite', 'database' => $arquivo, 'journal_mode' => 'WAL']);
    $escrita->statement('PRAGMA wal_autocheckpoint = 0');
    $escrita->update('UPDATE knowledge SET content = ? WHERE id = 1', ['PADRÕES <script>alert("memoria")</script>']);
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        expect(file_exists($arquivo.'-wal'))->toBeTrue();
        Livewire::test(Conhecimento::class)->set('memoria', 1)
            ->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert("memoria")</script>', false)
            ->set('busca', 'padrões')->assertSee('PADRÕES')
            ->set('agente', "' OR 1=1 --")->assertSee('Nenhuma memória encontrada');
    } finally {
        $escrita->disconnect();
        unlink($arquivo);
    }
});

test('paginação limita o grafo e permite explorar trechos antigos', function () {
    $arquivo = indiceRagDeTeste();
    $escrita = DB::build(['driver' => 'sqlite', 'database' => $arquivo]);
    for ($id = 4; $id <= 65; $id++) {
        $escrita->insert('INSERT INTO knowledge VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, 'antigas.md', "Trecho antigo $id", pack('g*', ...array_pad([1], 384, 0)), 'arquitetura', 'walle-arquiteto', '2026-10-26 00:00:00']);
    }
    $escrita->disconnect();
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        $dados = app(IndiceConhecimento::class)->carregar();
        expect($dados['nos'])->toHaveCount(24);
        expect(app(IndiceConhecimento::class)->mapa()['nos'])->toHaveCount(65);
        Livewire::test(Conhecimento::class)->assertDontSee('Ler memória #65')
            ->call('irParaPagina', 3)->assertSee('Ler memória #65')
            ->call('irParaPagina', -1)->assertSet('pagina', 1)
            ->set('pagina', 9999)->assertSee('Ler memória #65')
            ->set('categoria', 'padroes')->assertSet('pagina', 1)->assertSee('Outra convenção');
    } finally {
        unlink($arquivo);
    }
});

test('índice vazio ou corrompido apresenta estados claros', function () {
    $arquivo = indiceRagDeTeste();
    $escrita = DB::build(['driver' => 'sqlite', 'database' => $arquivo]);
    $escrita->delete('DELETE FROM knowledge');
    $escrita->delete('DELETE FROM documents');
    $escrita->disconnect();
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        $this->get('/conhecimento')->assertOk()->assertSee('Nenhuma memória encontrada');
        file_put_contents($arquivo, 'indice corrompido');
        $this->get('/conhecimento')->assertOk()->assertSee('O índice do RAG não está disponível');
    } finally {
        unlink($arquivo);
    }
});

/** Vetor de 384 posições a partir das primeiras, como o embedding do RAG. */
function vetorDeTeste(array $inicio): array
{
    return array_map('floatval', array_pad($inicio, 384, 0));
}

test('o mapa liga só acima do limiar, aproxima as memórias parecidas e separa os grupos', function () {
    $memorias = [];
    foreach (range(1, 5) as $i) {
        $memorias[] = ['id' => $i, 'agente' => 'walle-robos', 'categoria' => 'padroes', 'vetor' => vetorDeTeste([1, 0.05 * $i, 0])];
        $memorias[] = ['id' => 10 + $i, 'agente' => 'walle-telas', 'categoria' => 'arquitetura', 'vetor' => vetorDeTeste([0, 0.05 * $i, 1])];
    }

    $mapa = app(MapaConhecimento::class)->montar($memorias, 0.65);
    $nos = collect($mapa['nos'])->keyBy('id');
    $distancia = fn (int $a, int $b): float => hypot($nos[$a]['x'] - $nos[$b]['x'], $nos[$a]['y'] - $nos[$b]['y']);

    expect($mapa['conexoes'])->not->toBeEmpty();
    foreach ($mapa['conexoes'] as $conexao) {
        expect($conexao['similaridade'])->toBeGreaterThanOrEqual(0.65)
            ->and($conexao['origem'] < 10)->toBe($conexao['destino'] < 10);
    }
    expect($nos->filter(fn (array $no): bool => $no['id'] < 10)->pluck('grupo')->unique())->toHaveCount(1)
        ->and($nos[1]['grupo'])->not->toBe($nos[11]['grupo'])
        ->and($mapa['grupos'])->toHaveCount(2)
        ->and($mapa['grupos'][0])->toMatchArray(['total' => 5])
        ->and($distancia(1, 2))->toBeLessThan($distancia(1, 11));
    foreach ($nos as $no) {
        expect($no['x'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(MapaConhecimento::LARGURA)
            ->and($no['y'])->toBeGreaterThanOrEqual(0)->toBeLessThanOrEqual(MapaConhecimento::ALTURA)
            ->and($no['grau'])->toBe(4);
    }
    expect(app(MapaConhecimento::class)->montar($memorias, 0.65))->toBe($mapa);
});

test('cada memória leva ao mapa só as suas ligações mais fortes', function () {
    $memorias = array_map(fn (int $i): array => ['id' => $i, 'agente' => 'walle-dados', 'categoria' => 'padroes', 'vetor' => vetorDeTeste([1, 0.01 * $i])], range(1, 12));

    $mapa = app(MapaConhecimento::class)->montar($memorias, 0.65);

    expect(count($mapa['conexoes']))->toBeLessThanOrEqual(12 * MapaConhecimento::LIGACOES_POR_NO)
        ->and(count($mapa['conexoes']))->toBeLessThan(12 * 11 / 2)
        ->and($mapa['vizinhos'][1])->toHaveCount(MapaConhecimento::VIZINHOS_GUARDADOS)
        ->and($mapa['vizinhos'][1][0]['id'])->toBe(2);
    foreach ($mapa['nos'] as $no) {
        expect($no['grau'])->toBeGreaterThanOrEqual(MapaConhecimento::LIGACOES_POR_NO);
    }
});

test('as ramificações seguem as ligações em dois níveis sem repetir memória', function () {
    $vizinhos = [
        1 => [['id' => 2, 'similaridade' => 0.9], ['id' => 3, 'similaridade' => 0.8]],
        2 => [['id' => 1, 'similaridade' => 0.9], ['id' => 4, 'similaridade' => 0.85], ['id' => 3, 'similaridade' => 0.7]],
        3 => [['id' => 1, 'similaridade' => 0.8], ['id' => 4, 'similaridade' => 0.75], ['id' => 5, 'similaridade' => 0.7]],
    ];

    $arvore = app(MapaConhecimento::class)->ramificacoes($vizinhos, 1);

    expect(array_map(fn (array $no): array => [$no['id'], $no['nivel'], $no['pai']], $arvore))
        ->toBe([[1, 0, null], [2, 1, 1], [4, 2, 2], [3, 1, 1], [5, 2, 3]])
        ->and(app(MapaConhecimento::class)->ramificacoes($vizinhos, 99))->toBe([]);
});

test('a tela abre as ramificações e as ligações da memória selecionada e corrige o limiar inválido', function () {
    $arquivo = indiceRagDeTeste();
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    $this->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        Livewire::withQueryParams(['limiar' => 40])->test(Conhecimento::class)
            ->assertSet('limiar', 65)
            ->assertSee(['Mapa do cérebro', 'Ligações no mapa', 'Grupos de assunto'])
            ->assertDontSee('Ramificações da memória')
            ->call('selecionar', 1)
            ->assertSet('memoria', 1)
            ->assertSee(['Ramificações da memória #1', 'Ligações mais fortes', 'Seguir para a memória #2', '100,0%', 'memoria-1.md'])
            ->call('selecionar', 3)
            ->assertSee('Nenhuma memória do recorte chega a 65%')
            ->assertDontSee('Ramificações da memória')
            ->set('limiar', 85)->assertSet('limiar', 85)
            ->set('limiar', 50)->assertSet('limiar', 65);
    } finally {
        unlink($arquivo);
    }
});
