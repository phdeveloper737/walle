<?php

use App\Contingencias\BuscaContingencias;
use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Contingencias\Contingencia;
use Illuminate\Support\Facades\DB;

test('junta as contingências do Versatus (banco walle) e do Sactus (servidor do cliente), com os itens', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaVersatus($cliente, ['cupom' => '100'], [['nome' => 'ARROZ 5KG'], ['nome' => 'FEIJAO 1KG']]);
    criarContingenciaSactus(['cupom' => '500'], [['plu_sdesc' => 'OLEO 900ML']]);
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(BuscaContingencias::class)->buscar($cliente);

    expect($resultado->pendentes)->toHaveCount(2)
        ->and($resultado->totalDo(Contingencia::VERSATUS))->toBe(1)
        ->and($resultado->totalDo(Contingencia::SACTUS))->toBe(1)
        ->and($resultado->totalNoPdvSactus)->toBe(1)
        ->and($resultado->avisoSactus)->toBeNull()
        ->and($resultado->tipo())->toBe('SACTUS e VERSATUS');

    [$versatus, $sactus] = $resultado->pendentes;

    expect(array_map(fn ($item) => $item->nome, $versatus->itens))->toBe(['ARROZ 5KG', 'FEIJAO 1KG'])
        ->and($sactus->motivo)->toBe('Rejeição: CFOP não permitido para o CST informado')
        ->and($sactus->itens[0]->nome)->toBe('OLEO 900ML')
        ->and($sactus->chaveNfce())->toHaveLength(44);
});

test('uma venda que cai em várias faixas de motivo aparece uma vez só', function () {
    $cliente = criarClienteHelpDesk();
    // "CST" e "CFOP" no motivo: o sistema antigo trazia a mesma venda duas vezes
    criarContingenciaSactus(['cupom' => '700', 'motivo' => 'Rejeição: CFOP não permitido para o CST informado']);
    servidorDoClienteNoBancoDeTestes();

    expect(app(BuscaContingencias::class)->buscar($cliente)->pendentes)->toHaveCount(1);
});

test('autorizadas na Sefaz do mês passado para cá saem das pendentes; as mais antigas continuam', function () {
    $this->travel(5)->days(); // longe da virada do mês
    $cliente = criarClienteHelpDesk();
    $recente = criarContingenciaSactus(['cupom' => '801']);
    $antiga = criarContingenciaSactus(['cupom' => '802']);
    criarContingenciaSactus(['cupom' => '803']);
    autorizarNaSefaz($cliente, $recente, now()->toDateString());
    autorizarNaSefaz($cliente, $antiga, now()->subMonths(3)->toDateString());
    servidorDoClienteNoBancoDeTestes();

    $resultado = app(BuscaContingencias::class)->buscar($cliente);

    expect(array_map(fn ($c) => $c->cupom, $resultado->pendentes))->toEqualCanonicalizing(['802', '803'])
        ->and(array_map(fn ($a) => $a->chave, $resultado->autorizadas))->toEqualCanonicalizing([$recente, $antiga]);
});

test('com o servidor do cliente fora do ar mostra o Versatus e avisa do Sactus', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaVersatus($cliente);
    servidorDoClienteForaDoAr();

    $resultado = app(BuscaContingencias::class)->buscar($cliente);

    expect($resultado->pendentes)->toHaveCount(1)
        ->and($resultado->avisoSactus)->toContain('O servidor do cliente não respondeu');
});

test('cliente sem informação técnica no Help Desk dá erro claro', function () {
    $cliente = (int) DB::connection('help')->table('c_cliente')->insertGetId(['razao_social' => 'SEM SERVIDOR', 'deletado' => 'N']);
    servidorDoClienteNoBancoDeTestes();

    expect(fn () => app(BuscaContingencias::class)->buscar($cliente))
        ->toThrow(FalhaConexaoCliente::class, 'não tem a informação técnica');
});

test('o resultado fica no cache e só consulta o servidor do cliente de novo ao recarregar', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaSactus();
    $this->mock(ConectorDeCliente::class)
        ->shouldReceive('conectar')
        ->twice()
        ->andReturn(new ConexaoComCliente(DB::connection(), DB::getDatabaseName(), fecharAoTerminar: false));

    $busca = app(BuscaContingencias::class);
    $busca->buscar($cliente);
    $busca->buscar($cliente);
    $busca->esquecer($cliente);
    $busca->buscar($cliente);
});
