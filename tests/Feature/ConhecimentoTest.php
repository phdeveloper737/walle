<?php

use App\Livewire\Conhecimento;
use App\Models\Usuario;
use App\Rag\IndiceConhecimento;
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
        expect($dados['conexoes'])->toHaveCount(1);
        expect($dados['conexoes'][0])->toMatchArray(['origem' => 1, 'destino' => 2, 'similaridade' => 1.0]);
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
        expect($dados['conexoes'])->toHaveCount(120);
        Livewire::test(Conhecimento::class)->assertDontSee('Trecho antigo 65')
            ->call('irParaPagina', 3)->assertSee('Trecho antigo 65')
            ->call('irParaPagina', -1)->assertSet('pagina', 1)
            ->set('pagina', 9999)->assertSee('Trecho antigo 65')
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
