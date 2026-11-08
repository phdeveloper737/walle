<?php

use App\Models\Usuario;
use App\Rag\IndiceConhecimento;
use Illuminate\Support\Facades\DB;

/*
| Navegador de verdade: o mapa do cérebro desenha o SVG, o Alpine inicia a física sem erro de
| JavaScript e clicar numa memória abre as ramificações dela. A lógica fica nos testes de Feature.
*/

/** Índice do RAG em SQLite temporário, como o do ConhecimentoTest (nome próprio para não repetir a função global). */
function indiceRagParaNavegador(): string
{
    $arquivo = tempnam(sys_get_temp_dir(), 'rag-navegador-');
    $banco = DB::build(['driver' => 'sqlite', 'database' => $arquivo, 'foreign_key_constraints' => false]);
    $banco->statement('CREATE TABLE knowledge (id INTEGER PRIMARY KEY, path TEXT, content TEXT, embedding BLOB, category TEXT, agent TEXT, created_at TEXT)');
    $banco->statement('CREATE TABLE documents (path TEXT PRIMARY KEY, indexed_at TEXT)');
    foreach ([[1, 'Memória sobre arquitetura', 'arquitetura', 'walle-arquiteto', [1, 0]], [2, 'Correção documentada', 'bugs_resolvidos', 'walle-robos', [1, 0]]] as [$id, $conteudo, $categoria, $agente, $vetor]) {
        $banco->insert('INSERT INTO knowledge VALUES (?, ?, ?, ?, ?, ?, ?)', [$id, "memoria-$id.md", $conteudo, pack('g*', ...array_pad($vetor, 384, 0)), $categoria, $agente, '2026-10-27 00:31:11']);
        $banco->insert('INSERT INTO documents VALUES (?, ?)', ["memoria-$id.md", '2026-10-27 00:31:11']);
    }
    $banco->disconnect();
    app()->instance(IndiceConhecimento::class, new IndiceConhecimento($arquivo));

    return $arquivo;
}

test('o mapa do cérebro abre no navegador e clicar numa memória abre as ramificações', function () {
    $arquivo = indiceRagParaNavegador();
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => [$usuario->id]]);

    try {
        visit('/conhecimento')
            ->assertSee('Mapa do cérebro')
            ->assertNoJavaScriptErrors()
            ->click('[aria-label="Abrir as ramificações da memória #1 de walle-arquiteto, 1 ligações"]')
            ->assertSee('Ramificações da memória #1')
            ->assertNoJavaScriptErrors();
    } finally {
        unlink($arquivo);
    }
});
