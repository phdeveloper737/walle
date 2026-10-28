<?php

use App\Conversor\CertificadoInvalido;
use App\Conversor\ConversorCertificado;
use App\Livewire\Conversor;
use App\Models\Auditoria;
use App\Models\Usuario;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\WritingKey;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;

const SENHA_DO_PFX_DE_TESTE = 'senha-do-pfx-7Qz!';

/**
 * Gera um .pfx de teste inteiramente em memória (chave RSA + certificado autoassinado). Nunca um certificado real.
 * A chave é gerada uma vez por processo: gerar RSA a cada teste deixaria a suíte lenta.
 */
function pfxDeTeste(string $senha = SENHA_DO_PFX_DE_TESTE): string
{
    static $par = null;

    if ($par === null) {
        // O openssl.cnf do contêiner só liga o provider legado (sem a seção [req]); o CSR usa o padrão do Debian
        $opcoes = ['digest_alg' => 'sha256', 'config' => '/usr/lib/ssl/openssl.cnf'];
        $chave = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA, ...$opcoes]);
        $pedido = openssl_csr_new(['commonName' => 'MERCADO TESTE LTDA:12345678000199'], $chave, $opcoes);
        $certificado = openssl_csr_sign($pedido, null, $chave, 365, $opcoes, 1);
        $par = [$certificado, $chave];
    }

    openssl_pkcs12_export($par[0], $pfx, $par[1], $senha);

    return $pfx;
}

/** Admin com a senha do Help Desk confirmada (opcional) para abrir o conversor. */
function entrarNoConversor(bool $admin = true, bool $senha = true): Usuario
{
    $usuario = Usuario::find(criarUsuarioHelpDesk());
    test()->actingAs($usuario);
    config(['walle.admins' => $admin ? [$usuario->id] : []]);

    if ($senha) {
        session()->put('walle.senha_confirmada_em', now()->getTimestamp());
    }

    return $usuario;
}

/**
 * Fotografia dos arquivos que poderiam receber o PFX: storage inteiro (menos as views compiladas,
 * que o Blade grava ao renderizar) e o diretório temporário do PHP e de upload.
 *
 * @return array<string, string>
 */
function arquivosQuePoderiamGuardarOCertificado(): array
{
    $raizes = array_unique(array_filter([
        storage_path(),
        sys_get_temp_dir(),
        (string) ini_get('upload_tmp_dir'),
    ]));
    $ignorar = storage_path('framework'.DIRECTORY_SEPARATOR.'views');
    $arquivos = [];

    foreach ($raizes as $raiz) {
        if (! is_dir($raiz)) {
            continue;
        }

        $iterador = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($raiz, FilesystemIterator::SKIP_DOTS));

        foreach ($iterador as $arquivo) {
            /** @var SplFileInfo $arquivo */
            if ($arquivo->isFile() && ! str_starts_with($arquivo->getPathname(), $ignorar)) {
                $arquivos[$arquivo->getPathname()] = $arquivo->getMTime().':'.$arquivo->getSize();
            }
        }
    }

    return $arquivos;
}

/**
 * Arquivos criados ou alterados desde a fotografia que guardam algum dos segredos.
 * Só o conteúdo conta no diretório temporário do sistema, que outros processos do contêiner também usam;
 * no storage, qualquer arquivo novo ou alterado já é falha.
 *
 * @param  array<string, string>  $antes
 * @param  list<string>  $segredos
 * @return list<string>
 */
function arquivosSuspeitos(array $antes, array $segredos): array
{
    $suspeitos = [];

    foreach (arquivosQuePoderiamGuardarOCertificado() as $caminho => $assinatura) {
        if (($antes[$caminho] ?? null) === $assinatura) {
            continue;
        }

        if (str_starts_with($caminho, storage_path())) {
            $suspeitos[] = $caminho;

            continue;
        }

        $conteudo = (string) @file_get_contents($caminho);

        foreach ($segredos as $segredo) {
            if (str_contains($conteudo, $segredo)) {
                $suspeitos[] = $caminho;
            }
        }
    }

    return $suspeitos;
}

/**
 * Liga os "gravadores" que não podem receber nada da conversão e devolve o que eles viram.
 *
 * @return object{logs: list<string>, cache: list<string>}
 */
function vigiarPersistencia(): object
{
    Queue::fake();
    $vigia = (object) ['logs' => [], 'cache' => []];

    Event::listen(MessageLogged::class, function (MessageLogged $evento) use ($vigia): void {
        $vigia->logs[] = $evento->message.' '.json_encode($evento->context);
    });
    Event::listen([WritingKey::class, KeyWritten::class], function (WritingKey|KeyWritten $evento) use ($vigia): void {
        $vigia->cache[] = $evento->key;
    });

    return $vigia;
}

/**
 * Trechos que nunca podem aparecer fora da resposta: senha, PFX (em base64) e o PEM.
 *
 * @return list<string>
 */
function segredosDaConversao(string $pfx, string $senha): array
{
    return [$senha, substr(base64_encode($pfx), 0, 48), substr(base64_encode($pfx), -48, 40), 'PRIVATE KEY', 'BEGIN CERTIFICATE'];
}

/** @param list<string> $segredos */
function semSegredos(mixed $valor, array $segredos): void
{
    $texto = is_string($valor) ? $valor : (string) json_encode($valor);

    foreach ($segredos as $segredo) {
        expect($texto)->not->toContain($segredo);
    }
}

test('um .pfx de teste com a senha certa vira PEM com certificado e chave, e Auditoria registra a conversão sem a senha', function () {
    entrarNoConversor();
    $pfx = pfxDeTeste();
    $segredos = segredosDaConversao($pfx, SENHA_DO_PFX_DE_TESTE);
    $tela = Livewire::test(Conversor::class)->assertOk();
    $antes = arquivosQuePoderiamGuardarOCertificado();
    $vigia = vigiarPersistencia();

    $tela->call('converter', base64_encode($pfx), SENHA_DO_PFX_DE_TESTE);

    $resposta = $tela->effects['returns'][0];
    expect($resposta['ok'])->toBeTrue()
        ->and($resposta['pem'])->toContain('-----BEGIN CERTIFICATE-----')
        ->and($resposta['pem'])->toContain('-----BEGIN PRIVATE KEY-----')
        ->and($resposta['titular'])->toBe('MERCADO TESTE LTDA:12345678000199')
        ->and($resposta['validoAte'])->toBe(now()->addDays(365)->format('d/m/Y'));

    // O PEM devolvido abre e a chave é a do certificado
    expect(openssl_x509_check_private_key($resposta['pem'], $resposta['pem']))->toBeTrue();

    $auditoria = Auditoria::where('acao', 'conversor.converter')->sole();
    expect($auditoria->usuario_id)->toBe(auth()->id())
        ->and($auditoria->detalhes)->toMatchArray(['resultado' => 'convertido', 'titular' => 'MERCADO TESTE LTDA:12345678000199']);
    semSegredos($auditoria->detalhes, $segredos);
    semSegredos($auditoria->getRawOriginal('detalhes'), $segredos);

    // Nada fica no estado do componente (que volta ao navegador e é reenviado), em sessão, cache, fila, log ou disco
    semSegredos($tela->snapshot, $segredos);
    semSegredos(session()->all(), $segredos);
    expect($vigia->logs)->toBe([])
        ->and($vigia->cache)->toBe([])
        ->and(arquivosSuspeitos($antes, $segredos))->toBe([]);
    Queue::assertNothingPushed();
});

test('senha errada ou conteúdo que não é .pfx mostra o erro; sucesso e falha não criam arquivos nem persistem senha/PFX/PEM/chave em sessão, cache, fila ou log', function (string $conteudo, string $senha, string $erro, string $resultado) {
    entrarNoConversor();
    $pfx = pfxDeTeste();
    $segredos = [...segredosDaConversao($pfx, SENHA_DO_PFX_DE_TESTE), $senha];
    $tela = Livewire::test(Conversor::class);
    $antes = arquivosQuePoderiamGuardarOCertificado();
    $vigia = vigiarPersistencia();

    $tela->call('converter', $conteudo === 'pfx' ? base64_encode($pfx) : $conteudo, $senha);

    $resposta = $tela->effects['returns'][0];
    expect($resposta['ok'])->toBeFalse()
        ->and($resposta['erro'])->toContain($erro)
        ->and($resposta)->not->toHaveKey('pem');

    $auditoria = Auditoria::where('acao', 'conversor.converter')->sole();
    expect($auditoria->detalhes)->toBe(['resultado' => $resultado]);

    semSegredos($tela->snapshot, $segredos);
    semSegredos(session()->all(), $segredos);
    semSegredos($resposta, $segredos);
    expect($vigia->logs)->toBe([])
        ->and($vigia->cache)->toBe([])
        ->and(arquivosSuspeitos($antes, $segredos))->toBe([]);
    Queue::assertNothingPushed();
})->with([
    'senha errada' => ['pfx', 'senha-errada-Xy9', 'Senha do certificado incorreta', 'senha_incorreta'],
    'texto que não é .pfx' => [base64_encode('isto não é um certificado'), 'qualquer-Xy9', 'não é um certificado .pfx', 'arquivo_invalido'],
    'base64 quebrado' => ['%%%não-é-base64%%%', 'qualquer-Xy9', 'não é um certificado .pfx', 'arquivo_invalido'],
    'arquivo vazio' => ['', 'qualquer-Xy9', 'Escolha o arquivo .pfx', 'arquivo_invalido'],
    'arquivo grande demais' => [base64_encode(str_repeat('A', 10 * 1024 + 1)), 'qualquer-Xy9', 'maior que 10 KB', 'arquivo_invalido'],
]);

test('quem não é admin não abre o conversor nem converte', function () {
    $usuario = entrarNoConversor(admin: false);

    Livewire::test(Conversor::class)->assertForbidden();

    // Admin que perdeu o acesso com a tela aberta: a ação confere o Gate de novo
    config(['walle.admins' => [$usuario->id]]);
    $conversor = Mockery::mock(ConversorCertificado::class);
    $conversor->shouldNotReceive('converter');
    app()->instance(ConversorCertificado::class, $conversor);
    $tela = Livewire::test(Conversor::class);
    config(['walle.admins' => []]);

    $tela->call('converter', base64_encode(pfxDeTeste()), SENHA_DO_PFX_DE_TESTE)->assertForbidden();

    expect(Auditoria::where('acao', 'conversor.converter')->count())->toBe(0);
});

test('sem a senha do Help Desk confirmada nada é convertido, a janela abre sem guardar o PFX e, confirmada, a tela pede para converter de novo', function () {
    entrarNoConversor(senha: false);
    $pfx = pfxDeTeste();
    $conversor = Mockery::mock(ConversorCertificado::class);
    $conversor->shouldNotReceive('converter');
    app()->instance(ConversorCertificado::class, $conversor);

    $tela = Livewire::test(Conversor::class)
        ->call('converter', base64_encode($pfx), SENHA_DO_PFX_DE_TESTE)
        ->assertSet('pedindoSenha', true)
        ->assertSet('acaoPendente', 'liberar')
        ->assertSet('argumentosPendentes', []);

    expect($tela->effects['returns'][0])->toMatchArray(['ok' => false, 'pedirSenha' => true]);
    semSegredos($tela->snapshot, segredosDaConversao($pfx, SENHA_DO_PFX_DE_TESTE));
    expect(Auditoria::where('acao', 'conversor.converter')->count())->toBe(0);

    $tela->set('senhaConfirmacao', 'segredo')
        ->call('confirmarSenha')
        ->assertSet('pedindoSenha', false)
        ->assertDispatched('conversor-liberado');
});

test('confirmação expirada pede a senha de novo antes de converter', function () {
    entrarNoConversor(senha: false);
    session()->put('walle.senha_confirmada_em', now()->subMinutes((int) config('walle.confirmar_senha_minutos') + 1)->getTimestamp());

    Livewire::test(Conversor::class)
        ->call('converter', base64_encode(pfxDeTeste()), SENHA_DO_PFX_DE_TESTE)
        ->assertSet('pedindoSenha', true);

    expect(Auditoria::where('acao', 'conversor.converter')->count())->toBe(0);
});

test('a tela recebe o .pfx sem upload do Livewire: nenhum input de arquivo ligado ao servidor nem formulário multipart', function () {
    entrarNoConversor();

    $html = Livewire::test(Conversor::class)->html();

    expect($html)->toContain('type="file"')
        ->not->toContain('wire:model')
        ->not->toContain('multipart/form-data')
        ->not->toContain('<form')
        ->and(class_uses_recursive(Conversor::class))->not->toContain(Livewire\WithFileUploads::class);
});

test('o corpo que o navegador envia com o .pfx no limite cabe nos 16 KB que o PHP-FPM guarda em memória', function () {
    entrarNoConversor();
    $tela = Livewire::test(Conversor::class);

    // O pior caso aceito: .pfx no limite (em base64, como o FileReader manda) e uma senha longa com acentos
    $pfxBase64 = base64_encode(random_bytes(ConversorCertificado::TAMANHO_MAXIMO));
    $senha = str_repeat('çã', 32);
    $snapshot = $tela->snapshot;
    // O caminho real da página entra no snapshot; um caminho longo de propósito, para não depender da rota
    $snapshot['memo']['path'] = str_repeat('caminho/', 12).'conversor';
    $json = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    // O mesmo envelope que o livewire.js monta (JSON.stringify não escapa "/" nem acentos)
    $corpo = json_encode([
        '_token' => str_repeat('t', 40),
        'components' => [[
            'snapshot' => json_encode($snapshot, $json),
            'updates' => (object) [],
            'calls' => [['method' => 'converter', 'params' => [$pfxBase64, $senha], 'metadata' => (object) []]],
        ]],
    ], $json);

    // SAPI_POST_BLOCK_SIZE do PHP: acima disso o php://input vai para arquivo temporário. Folga de 1 KB.
    expect(strlen($corpo))->toBeLessThan(16_384 - 1024);
});

test('o serviço converte em memória e recusa senha errada com exceção clara', function () {
    $convertido = app(ConversorCertificado::class)->converter(pfxDeTeste(), SENHA_DO_PFX_DE_TESTE);

    expect($convertido->pem())->toStartWith('-----BEGIN CERTIFICATE-----')
        ->and($convertido->chave)->toContain('PRIVATE KEY')
        ->and(print_r($convertido, true))->not->toContain('PRIVATE KEY')
        ->and(fn () => serialize($convertido))->toThrow(LogicException::class);

    expect(fn () => app(ConversorCertificado::class)->converter(pfxDeTeste(), 'errada'))
        ->toThrow(CertificadoInvalido::class, 'Senha do certificado incorreta');
});
