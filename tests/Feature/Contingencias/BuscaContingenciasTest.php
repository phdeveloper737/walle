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

test('o mesmo número de cupom em outro PDV não duplica nem mistura os itens', function () {
    $cliente = criarClienteHelpDesk();
    // No sistema antigo o JOIN só pelo cupom juntava as duas vendas
    criarContingenciaSactus(['pdv' => '1', 'cupom' => '900'], [['plu_sdesc' => 'ITEM DO PDV 1']]);
    criarContingenciaSactus(['pdv' => '2', 'cupom' => '900'], [['plu_sdesc' => 'ITEM DO PDV 2'], ['plu_sdesc' => 'OUTRO DO PDV 2']]);
    servidorDoClienteNoBancoDeTestes();

    $porPdv = collect(app(BuscaContingencias::class)->buscar($cliente)->pendentes)->keyBy('pdv');

    expect(array_map(fn ($i) => $i->nome, $porPdv['1']->itens))->toBe(['ITEM DO PDV 1'])
        ->and(array_map(fn ($i) => $i->nome, $porPdv['2']->itens))->toBe(['ITEM DO PDV 2', 'OUTRO DO PDV 2']);
});

test('itens de outro dia com o mesmo cupom não entram na contingência', function () {
    $cliente = criarClienteHelpDesk();
    criarContingenciaSactus(['cupom' => '910', 'fiscal_date' => '2026-10-19 09:00:00'], [['plu_sdesc' => 'VENDA DE HOJE']]);
    // Venda antiga (já autorizada, sem contingência) com o mesmo número de cupom
    DB::table('sale_itens')->insert([
        'store' => '1', 'pdv' => '2', 'cupom' => '910', 'fiscal_date' => '2026-01-05 15:00:00', 'seq' => 1, 'plu_sdesc' => 'VENDA DE JANEIRO',
    ]);
    servidorDoClienteNoBancoDeTestes();

    $itens = app(BuscaContingencias::class)->buscar($cliente)->pendentes[0]->itens;

    expect(array_map(fn ($i) => $i->nome, $itens))->toBe(['VENDA DE HOJE']);
});

test('sem a chave na contingencias_pdv (Sactus antigo), busca a chave na sale', function () {
    $cliente = criarClienteHelpDesk();
    $chave = criarContingenciaSactus(['cupom' => '920'], chaveNaContingencia: false);
    servidorDoClienteNoBancoDeTestes();

    $contingencia = app(BuscaContingencias::class)->buscar($cliente)->pendentes[0];

    expect($contingencia->chaveNfce())->toBe($chave)
        ->and($contingencia->itens[0]->chave)->toBe($chave);
});
