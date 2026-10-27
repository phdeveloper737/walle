<?php

namespace App\Contingencias;

use App\Contingencias\Cliente\ConectorDeCliente;
use App\Contingencias\Cliente\ConexaoComCliente;
use App\Contingencias\Cliente\FalhaConexaoCliente;
use App\Models\Auditoria;
use App\Sactus\ClienteSactus;
use App\Sactus\Ssh\AlvoSsh;
use App\Sactus\Ssh\ConectorSsh;
use App\Sactus\Ssh\FalhaSsh;
use App\Sactus\Ssh\SessaoSsh;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Ações manuais de admin nas contingências de um cliente (substituem find.sale.php e docker.php do 220):
 * lê o MySQL do cliente (só leitura) e grava o XML de comando em /var/sactus/snd por SSH, no contêiner do cliente.
 *
 * - reenviarAoPdv: funcid 858 com a chave de acesso do cupom (find.sale.php:94-101);
 * - reprocessar: funcid 556 "ajustar_contingencias" para cada PDV da tabela pdv (docker.php:555-613), com o
 *   remapeamento de clientes do 220 (docker.php:310-321); para no primeiro erro.
 *
 * Só rodam com o robô das contingências ligado. Cada chamada é auditada, também quando falha.
 *
 * Diferenças: SSH com a credencial DOCKER do cadastro (sem host, senha ou sshpass fixos, sem docker exec como root
 * no host); sem a marcação reprocessar=S do Versatus (a tabela não existe no walle) e sem escrita no Help Desk;
 * o carimbo de hora sobe 1 s por arquivo e, se o nome já existir, tenta o segundo seguinte (até 5 vezes); a chave do
 * cupom também é buscada pela data fiscal (o mesmo cupom pode existir em dias diferentes); a gravação segue o
 * docker.php:155-204 (temporário sem .xml, confere que não está vazio e publica com ln, sem sobrescrever), e não
 * cria a pasta de envio (docker.php:174-175).
 */
class AcoesContingencias
{
    /** Cliente pedido => cliente cujo servidor atende (docker.php:310-317). */
    public const REMAPEAMENTO = [1484 => 1485, 1657 => 1637, 1656 => 1637, 1649 => 1637, 1807 => 1804, 1814 => 1804];

    public const TIMEOUT_SSH_SEGUNDOS = 15;

    /** Tentativas de nome (segundo, segundo+1...) quando o arquivo de comando já existe. */
    public const TENTATIVAS_DE_NOME = 5;

    private const RC_SEM_DIRETORIO = 3;

    private const RC_DESTINO_EXISTE = 4;

    private const RC_SEM_ESCRITA = 6;

    public const DESLIGADO = 'O robô das contingências está desligado; a ação não foi enviada.';

    public function __construct(
        private readonly ConectorDeCliente $conector,
        private readonly ConectorSsh $ssh,
    ) {}

    public function ligado(): bool
    {
        return (bool) config('walle.contingencias.ativo');
    }

    /**
     * @param  list<array{loja: int, pdv: int, cupom: int, data?: string}>  $cupons
     */
    public function reenviarAoPdv(int $clienteId, array $cupons): ResultadoAcao
    {
        $identificados = array_map(fn (array $c): string => "{$c['loja']}/{$c['pdv']}/{$c['cupom']}", $cupons);

        return $this->executar('contingencias.reenviar_pdv', $clienteId, $identificados, function () use ($clienteId, $cupons): ResultadoAcao {
            $sessao = null;

            try {
                [$servidor, $alvos] = $this->servidorEAlvos($clienteId);
                $chaves = [];
                $falhas = [];
                $conexao = $this->conector->conectar($clienteId, $servidor);

                try {
                    foreach ($cupons as $cupom) {
                        $nome = "{$cupom['loja']}/{$cupom['pdv']}/{$cupom['cupom']}";

                        if (! $this->faixaValida($cupom['loja'], $cupom['pdv']) || $cupom['cupom'] < 1) {
                            $falhas[] = "Cupom {$nome} com loja, PDV ou número inválido.";

                            continue;
                        }

                        $chave = $this->chaveDoCupom($conexao, $cupom);

                        if (preg_match('/^\d{44}$/', $chave) !== 1) {
                            $falhas[] = "Cupom {$nome} sem chave de acesso no banco do cliente.";

                            continue;
                        }

                        $chaves[] = ['loja' => $cupom['loja'], 'pdv' => $cupom['pdv'], 'chave' => $chave, 'nome' => $nome];
                    }
                } finally {
                    $conexao->desconectar();
                }

                $enviados = 0;

                if ($chaves !== []) {
                    $sessao = $this->abrirSessao($alvos);
                    $inicio = now()->getTimestamp();

                    foreach ($chaves as $i => $c) {
                        $erro = $this->gravar($sessao, $c['loja'], $c['pdv'], $inicio + $i, 858, $c['chave'], preenchido: true);

                        if ($erro === null) {
                            $enviados++;
                        } else {
                            $falhas[] = "Cupom {$c['nome']}: {$erro}";
                        }
                    }
                }

                $total = count($cupons);
                $texto = $enviados === 1 ? '1 cupom enviado' : "{$enviados} cupons enviados";

                return new ResultadoAcao(
                    $falhas === [],
                    $falhas === []
                        ? "{$texto} ao PDV. O Sactus responde em cerca de 5 minutos."
                        : "{$texto} ao PDV de {$total} pedidos; {$this->contar(count($falhas), 'falha')}.",
                    $enviados,
                    $falhas,
                );
            } catch (FalhaConexaoCliente|FalhaSsh $falha) {
                return ResultadoAcao::falha($falha->getMessage());
            } catch (QueryException $erro) {
                report($erro);

                return ResultadoAcao::falha('Não foi possível ler a chave de acesso no banco do cliente.');
            } finally {
                $sessao?->fechar();
            }
        });
    }

    public function reprocessar(int $pedido): ResultadoAcao
    {
        $clienteId = self::REMAPEAMENTO[$pedido] ?? $pedido;

        return $this->executar('contingencias.reprocessar', $pedido, [], function () use ($clienteId): ResultadoAcao {
            $sessao = null;
            $enviados = 0;

            try {
                [$servidor, $alvos] = $this->servidorEAlvos($clienteId);
                $conexao = $this->conector->conectar($clienteId, $servidor);

                try {
                    $pdvs = $conexao->conexao->select("SELECT pdv_id, store_id FROM {$conexao->tabela('pdv')} ORDER BY store_id, pdv_id");
                } finally {
                    $conexao->desconectar();
                }

                if ($pdvs === []) {
                    return ResultadoAcao::falha('Nenhum PDV foi encontrado no banco do cliente. Nenhum XML foi gravado.');
                }

                $sessao = $this->abrirSessao($alvos);
                $inicio = now()->getTimestamp();

                foreach ($pdvs as $i => $linha) {
                    $loja = (int) $linha->store_id;
                    $pdv = (int) $linha->pdv_id;

                    if (! $this->faixaValida($loja, $pdv)) {
                        throw self::falhaDeAcao('Foi encontrado um cadastro de PDV com loja ou número inválido.'.$this->parcial($enviados));
                    }

                    $erro = $this->gravar($sessao, $loja, $pdv, $inicio + $i, 556, 'ajustar_contingencias', preenchido: false);

                    if ($erro !== null) {
                        throw self::falhaDeAcao("Loja {$loja}, PDV {$pdv}: {$erro}".$this->parcial($enviados));
                    }

                    $enviados++;
                }

                return new ResultadoAcao(true, "Reprocessamento pedido: {$this->contar($enviados, 'PDV', 'PDVs')} receberam o comando. O Sactus responde em cerca de 5 minutos.", $enviados);
            } catch (FalhaConexaoCliente|FalhaSsh $falha) {
                return new ResultadoAcao(false, $falha->getMessage(), $enviados);
            } catch (QueryException $erro) {
                report($erro);

                return ResultadoAcao::falha('Não foi possível ler a lista de PDVs no banco do cliente.');
            } finally {
                $sessao?->fechar();
            }
        }, $clienteId);
    }

    /**
     * Desligado, trava por cliente, auditoria e erros inesperados em um lugar só.
     *
     * @param  list<string>  $cupons
     * @param  callable(): ResultadoAcao  $acao
     * @param  int|null  $servidorId  cliente cujo servidor atende (a trava é por servidor, não pelo id pedido)
     */
    private function executar(string $nome, int $clienteId, array $cupons, callable $acao, ?int $servidorId = null): ResultadoAcao
    {
        if (! $this->ligado()) {
            $resultado = ResultadoAcao::falha(self::DESLIGADO);
            $this->auditar($nome, $clienteId, $cupons, $resultado, 'desligado');

            return $resultado;
        }

        $trava = Cache::lock('contingencias:acao:'.($servidorId ?? $clienteId), 120);

        if (! $trava->get()) {
            $resultado = ResultadoAcao::falha('Já existe uma ação em andamento neste cliente. Tente de novo em instantes.');
            $this->auditar($nome, $clienteId, $cupons, $resultado, 'ocupado');

            return $resultado;
        }

        try {
            $resultado = $acao();
        } catch (Throwable $erro) {
            report($erro);
            $resultado = ResultadoAcao::falha('Não foi possível concluir a ação agora. O detalhe ficou no log.');
        } finally {
            $trava->release();
        }

        $this->auditar($nome, $clienteId, $cupons, $resultado, match (true) {
            $resultado->ok => 'ok',
            $resultado->enviados > 0 => 'parcial',
            default => 'falha',
        });

        return $resultado;
    }

    /** @param list<string> $cupons */
    private function auditar(string $nome, int $clienteId, array $cupons, ResultadoAcao $resultado, string $situacao): void
    {
        Auditoria::registrar($nome, $clienteId, array_filter([
            'quantidade' => $cupons === [] ? $resultado->enviados : count($cupons),
            'enviados' => $resultado->enviados,
            'resultado' => $situacao,
            'cupons' => $cupons === [] ? null : $cupons,
        ], fn (mixed $valor): bool => $valor !== null));
    }

    /**
     * Linha do Help Desk (ao vivo, só leitura) e os alvos SSH do contêiner.
     *
     * @return array{0: array<string, mixed>, 1: list<AlvoSsh>}
     */
    private function servidorEAlvos(int $clienteId): array
    {
        $linha = DB::connection('help')->table('informacao_tecnica_dataserver as i')
            ->join('c_cliente as c', 'c.id', '=', 'i.fk_c_cliente')
            ->where('c.id', $clienteId)
            ->orderByDesc('i.id')
            ->select('i.*', 'c.cnpj')
            ->first() ?? throw FalhaConexaoCliente::semCadastroNoHelpDesk();

        $servidor = array_map(fn ($valor) => is_string($valor) ? trim($valor) : $valor, (array) $linha);
        $alvos = array_values(array_filter(
            ClienteSactus::daLinha([...$servidor, 'id_client' => $clienteId])->alvos(false),
            fn (AlvoSsh $alvo): bool => $alvo->metodo === 'DOCKER',
        ));

        if ($alvos === []) {
            throw self::falhaDeAcao('Cadastro SSH do contêiner incompleto');
        }

        return [$servidor, $alvos];
    }

    /** @param list<AlvoSsh> $alvos */
    private function abrirSessao(array $alvos): SessaoSsh
    {
        $ultima = null;

        foreach ($alvos as $alvo) {
            try {
                return $this->ssh->abrir($alvo);
            } catch (FalhaSsh $falha) {
                $ultima = $falha;
            }
        }

        throw $ultima ?? self::falhaDeAcao('Cadastro SSH do contêiner incompleto');
    }

    /** Falha com texto próprio, seguro para a tela. */
    private static function falhaDeAcao(string $mensagem): FalhaConexaoCliente
    {
        return new FalhaConexaoCliente('ERRO_ACAO', $mensagem);
    }

    /** @param array{loja: int, pdv: int, cupom: int, data?: string} $cupom */
    private function chaveDoCupom(ConexaoComCliente $conexao, array $cupom): string
    {
        $data = trim($cupom['data'] ?? '');
        $linha = $conexao->conexao->selectOne(
            "SELECT CAST(chave_acesso_nfce AS CHAR(255)) AS chave_acesso_nfce FROM {$conexao->tabela('sale')} WHERE store = ? AND pdv = ? AND cupom = ?".($data === '' ? '' : ' AND fiscal_date = ?').' LIMIT 1',
            $data === '' ? [$cupom['loja'], $cupom['pdv'], $cupom['cupom']] : [$cupom['loja'], $cupom['pdv'], $cupom['cupom'], $data],
        );

        return trim((string) ($linha->chave_acesso_nfce ?? ''));
    }

    private function faixaValida(int $loja, int $pdv): bool
    {
        return $loja >= 1 && $loja <= 9999 && $pdv >= 1 && $pdv <= 999;
    }

    /** $preenchido: loja com 4 e PDV com 3 dígitos no atributo (find.sale.php); sem zeros no reprocessamento (docker.php). */
    private function xml(int $loja, int $pdv, int $funcao, string $argumento, int $momento, bool $preenchido): string
    {
        $lojaXml = $preenchido ? $this->lojaComZeros($loja) : (string) $loja;
        $pdvXml = $preenchido ? $this->pdvComZeros($pdv) : (string) $pdv;

        return '<?xml version="1.0" encoding="ISO-8859-1"?>'
            ."<comando store=\"{$lojaXml}\" pdv=\"{$pdvXml}\" funcid=\"{$funcao}\" argum=\"{$argumento}\" reqtime=\"{$momento}\"></comando>";
    }

    private function lojaComZeros(int $loja): string
    {
        return str_pad((string) $loja, 4, '0', STR_PAD_LEFT);
    }

    private function pdvComZeros(int $pdv): string
    {
        return str_pad((string) $pdv, 3, '0', STR_PAD_LEFT);
    }

    /**
     * Grava o XML sem sobrescrever (docker.php:155-204): temporário sem .xml, confere que não está vazio e publica
     * com ln, que falha se o destino existir; nesse caso tenta o segundo seguinte. Não cria a pasta de envio.
     *
     * @return string|null null quando gravou; senão o texto do erro, seguro para a tela
     */
    private function gravar(SessaoSsh $sessao, int $loja, int $pdv, int $momento, int $funcao, string $argumento, bool $preenchido): ?string
    {
        $lojaPasta = $this->lojaComZeros($loja);
        $pdvPasta = $this->pdvComZeros($pdv);
        $diretorio = "/var/sactus/snd/{$lojaPasta}/{$pdvPasta}";

        for ($tentativa = 0; $tentativa < self::TENTATIVAS_DE_NOME; $tentativa++) {
            $segundo = $momento + $tentativa;
            $arquivo = "{$diretorio}/comando_{$lojaPasta}_{$pdvPasta}_{$segundo}_102.xml";
            $xml = $this->xml($loja, $pdv, $funcao, $argumento, $segundo, $preenchido);

            try {
                $resultado = $sessao->executar($this->scriptDeGravacao($diretorio, $arquivo, $xml), self::TIMEOUT_SSH_SEGUNDOS);
            } catch (Throwable $erro) {
                report($erro);

                return 'não foi possível gravar o XML no servidor do cliente.';
            }

            if ($resultado->ok()) {
                return null;
            }

            if ($resultado->rc === self::RC_SEM_DIRETORIO) {
                return 'Diretório de envio não existe.';
            }

            if ($resultado->rc === self::RC_SEM_ESCRITA) {
                return 'Diretório de envio sem permissão de escrita.';
            }

            if ($resultado->rc !== self::RC_DESTINO_EXISTE) {
                return 'não foi possível gravar o XML no servidor do cliente.';
            }
        }

        return 'já existem arquivos de comando com o mesmo nome; tente de novo em instantes.';
    }

    private function scriptDeGravacao(string $diretorio, string $arquivo, string $xml): string
    {
        $dir = escapeshellarg($diretorio);
        $destino = escapeshellarg($arquivo);
        $temporario = escapeshellarg($arquivo.'.tmp_'.bin2hex(random_bytes(8)));

        return implode('; ', [
            "[ -d {$dir} ] || exit ".self::RC_SEM_DIRETORIO,
            "[ -w {$dir} ] || exit ".self::RC_SEM_ESCRITA,
            "[ ! -e {$destino} ] || exit ".self::RC_DESTINO_EXISTE,
            'printf '.escapeshellarg('%s').' '.escapeshellarg($xml)." > {$temporario} && [ -s {$temporario} ] && ln {$temporario} {$destino}",
            'rc=$?',
            "rm -f {$temporario}",
            "[ \$rc -eq 0 ] || { [ -e {$destino} ] && exit ".self::RC_DESTINO_EXISTE.'; exit 1; }',
        ]);
    }

    private function parcial(int $enviados): string
    {
        return $enviados > 0 ? " Envio parcial: {$enviados} arquivo(s) já foram gravados." : '';
    }

    private function contar(int $quantidade, string $singular, ?string $plural = null): string
    {
        return $quantidade.' '.($quantidade === 1 ? $singular : ($plural ?? $singular.'s'));
    }
}
