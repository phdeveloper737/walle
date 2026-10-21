<?php

namespace App\Autorizadas;

use App\Sefaz\ChaveDeAcesso;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Números do painel das Autorizadas, tirados da history_contingencia_auth
 * (substitui o autorizadas.analise.php e os números do autorizadas.dash.php antigos).
 *
 * A tabela é uma foto: uma linha por chave. A referência é o eixo do tempo de tudo;
 * data_consulta_sefaz_bot é só a ÚLTIMA consulta da chave (serve para medir a atividade de agora).
 */
class AnaliseAutorizadas
{
    public function __construct(private readonly RoboAutorizadas $robo) {}

    /**
     * Situação da base inteira numa varredura só. "fila" é o mesmo WHERE do robô
     * (não autorizada, de GO, nunca consultada ou consultada há mais de N horas).
     *
     * @return array{total: int, autorizadas: int, pendentes: int, fila: int, outras_uf: int, nunca: int, clientes: int, hoje: int, hora: int, ultima: Carbon|null, taxa: float}
     */
    public function geral(): array
    {
        $l = DB::table('history_contingencia_auth')->selectRaw("
            COUNT(*) AS total,
            SUM(autorizada = 'S') AS autorizadas,
            SUM(autorizada = 'N') AS pendentes,
            SUM(autorizada = 'N' AND chave LIKE '52%' AND (data_consulta_sefaz_bot IS NULL OR data_consulta_sefaz_bot <= ?)) AS fila,
            SUM(autorizada = 'N' AND chave NOT LIKE '52%') AS outras_uf,
            SUM(data_consulta_sefaz_bot IS NULL) AS nunca,
            COUNT(DISTINCT fk_c_cliente) AS clientes,
            SUM(data_consulta_sefaz_bot >= ?) AS hoje,
            SUM(data_consulta_sefaz_bot >= ?) AS hora,
            MAX(data_consulta_sefaz_bot) AS ultima
        ", [
            now()->subHours((int) config('walle.autorizadas.intervalo_horas')),
            now()->startOfDay(),
            now()->subHour(),
        ])->first();

        $total = (int) $l->total;
        $autorizadas = (int) $l->autorizadas;

        return [
            'total' => $total,
            'autorizadas' => $autorizadas,
            'pendentes' => (int) $l->pendentes,
            'fila' => (int) $l->fila,
            'outras_uf' => (int) $l->outras_uf,
            'nunca' => (int) $l->nunca,
            'clientes' => (int) $l->clientes,
            'hoje' => (int) $l->hoje,
            'hora' => (int) $l->hora,
            'ultima' => $l->ultima !== null ? Carbon::parse($l->ultima) : null,
            'taxa' => self::taxa($autorizadas, $total),
        ];
    }

    /**
     * Volume por semana (segunda a domingo) dentro da retenção, sem buraco na linha do tempo.
     * O antigo mostrava 12 meses, mas a base só guarda 73 dias: sobravam 9 meses vazios.
     *
     * @return list<array{inicio: string, rotulo: string, total: int, autorizadas: int, pendentes: int, taxa: float}>
     */
    public function semanas(): array
    {
        // Segunda-feira fixa, igual ao WEEKDAY() do MySQL (no pt_BR o Carbon começaria a semana no domingo)
        $primeira = Carbon::parse($this->robo->corteRetencao())->startOfWeek(Carbon::MONDAY);
        $linhas = DB::table('history_contingencia_auth')
            ->where('referencia', '>=', $primeira->toDateString())
            ->selectRaw("DATE_SUB(referencia, INTERVAL WEEKDAY(referencia) DAY) AS semana, COUNT(*) AS total, SUM(autorizada = 'S') AS autorizadas")
            ->groupBy('semana')
            ->get()
            ->keyBy(fn (object $l): string => substr((string) $l->semana, 0, 10));

        $serie = [];

        for ($semana = $primeira->copy(); $semana->lte(now()); $semana->addWeek()) {
            $l = $linhas->get($semana->toDateString());
            $total = (int) ($l->total ?? 0);
            $autorizadas = (int) ($l->autorizadas ?? 0);

            $serie[] = [
                'inicio' => $semana->toDateString(),
                'rotulo' => $semana->format('d/m'),
                'total' => $total,
                'autorizadas' => $autorizadas,
                'pendentes' => $total - $autorizadas,
                'taxa' => self::taxa($autorizadas, $total),
            ];
        }

        return $serie;
    }

    /**
     * Contingências por dia do mês: mês atual × anterior, alinhados pelo dia.
     * Os dias que ainda não chegaram no mês atual ficam null (a linha para em hoje).
     *
     * @return array{dias: list<int>, atual: list<int|null>, anterior: list<int|null>, rotulo_atual: string, rotulo_anterior: string}
     */
    public function diario(): array
    {
        $inicioAtual = now()->startOfMonth();
        $inicioAnterior = now()->subMonthNoOverflow()->startOfMonth();

        $porDia = DB::table('history_contingencia_auth')
            ->whereBetween('referencia', [$inicioAnterior->toDateString(), now()->endOfMonth()->toDateString()])
            ->selectRaw('referencia AS dia, COUNT(*) AS total')
            ->groupBy('referencia')
            ->pluck('total', 'dia')
            ->mapWithKeys(fn ($total, $dia): array => [substr((string) $dia, 0, 10) => (int) $total]);

        $dias = range(1, max($inicioAtual->daysInMonth, $inicioAnterior->daysInMonth));
        $hoje = now()->day;

        return [
            'dias' => $dias,
            'atual' => array_map(fn (int $d): ?int => $d <= $hoje ? ($porDia[$inicioAtual->copy()->day($d)->toDateString()] ?? 0) : null, $dias),
            'anterior' => array_map(fn (int $d): ?int => $d <= $inicioAnterior->daysInMonth ? ($porDia[$inicioAnterior->copy()->day($d)->toDateString()] ?? 0) : null, $dias),
            'rotulo_atual' => ucfirst($inicioAtual->translatedFormat('F')),
            'rotulo_anterior' => ucfirst($inicioAnterior->translatedFormat('F')),
        ];
    }

    /** @return array{total: int, autorizadas: int, pendentes: int, nunca: int, clientes: int, cnpjs: int, taxa: float} */
    public function periodo(Periodo $periodo): array
    {
        $l = $this->noPeriodo($periodo)->selectRaw("
            COUNT(*) AS total,
            SUM(autorizada = 'S') AS autorizadas,
            SUM(data_consulta_sefaz_bot IS NULL) AS nunca,
            COUNT(DISTINCT fk_c_cliente) AS clientes,
            COUNT(DISTINCT SUBSTRING(chave, 7, 14)) AS cnpjs
        ")->first();

        $total = (int) $l->total;
        $autorizadas = (int) $l->autorizadas;

        return [
            'total' => $total,
            'autorizadas' => $autorizadas,
            'pendentes' => $total - $autorizadas,
            'nunca' => (int) $l->nunca,
            'clientes' => (int) $l->clientes,
            'cnpjs' => (int) $l->cnpjs,
            'taxa' => self::taxa($autorizadas, $total),
        ];
    }

    /**
     * Clientes com mais contingências no período.
     *
     * @return list<array{cliente: int, total: int, autorizadas: int, pendentes: int, taxa: float}>
     */
    public function clientes(Periodo $periodo, int $limite = 12): array
    {
        return $this->noPeriodo($periodo)
            ->selectRaw("fk_c_cliente AS cliente, COUNT(*) AS total, SUM(autorizada = 'S') AS autorizadas")
            ->groupBy('fk_c_cliente')
            ->orderByDesc('total')
            ->limit($limite)
            ->get()
            ->map(fn (object $l): array => [
                'cliente' => (int) $l->cliente,
                'total' => (int) $l->total,
                'autorizadas' => (int) $l->autorizadas,
                'pendentes' => (int) $l->total - (int) $l->autorizadas,
                'taxa' => self::taxa((int) $l->autorizadas, (int) $l->total),
            ])
            ->all();
    }

    /**
     * Perfil fiscal tirado da própria chave (UF, modelo e tipo de emissão), numa consulta só.
     *
     * @return array<'ufs'|'modelos'|'emissoes', list<array{rotulo: string, total: int, autorizadas: int, taxa: float}>>
     */
    public function perfil(Periodo $periodo): array
    {
        $linhas = $this->noPeriodo($periodo)
            ->whereRaw('CHAR_LENGTH(chave) = 44')
            ->selectRaw("SUBSTRING(chave, 1, 2) AS uf, SUBSTRING(chave, 21, 2) AS modelo, SUBSTRING(chave, 35, 1) AS tp_emis, COUNT(*) AS total, SUM(autorizada = 'S') AS autorizadas")
            ->groupBy('uf', 'modelo', 'tp_emis')
            ->get();

        $grupos = ['ufs' => [], 'modelos' => [], 'emissoes' => []];

        foreach ($linhas as $l) {
            foreach ([
                'ufs' => ChaveDeAcesso::siglaUf((string) $l->uf),
                'modelos' => ChaveDeAcesso::rotuloModelo((string) $l->modelo),
                'emissoes' => ChaveDeAcesso::rotuloTipoEmissao((string) $l->tp_emis),
            ] as $grupo => $rotulo) {
                $grupos[$grupo][$rotulo] ??= ['rotulo' => $rotulo, 'total' => 0, 'autorizadas' => 0];
                $grupos[$grupo][$rotulo]['total'] += (int) $l->total;
                $grupos[$grupo][$rotulo]['autorizadas'] += (int) $l->autorizadas;
            }
        }

        return array_map(function (array $itens): array {
            $itens = array_values($itens);
            usort($itens, fn (array $a, array $b): int => $b['total'] <=> $a['total']);

            return array_map(fn (array $i): array => [...$i, 'taxa' => self::taxa($i['autorizadas'], $i['total'])], $itens);
        }, $grupos);
    }

    /**
     * Dias entre a referência e a consulta que confirmou a autorização. Só vale para as autorizadas:
     * nelas a última consulta gravada é justamente a que autorizou.
     *
     * @return array{faixas: list<array{rotulo: string, total: int}>, media: float, maior: int, total: int}
     */
    public function latencia(Periodo $periodo): array
    {
        $dias = 'DATEDIFF(data_consulta_sefaz_bot, referencia)';
        $l = $this->noPeriodo($periodo)
            ->where('autorizada', 'S')
            ->whereNotNull('data_consulta_sefaz_bot')
            ->selectRaw("
                SUM({$dias} <= 0) AS f0,
                SUM({$dias} = 1) AS f1,
                SUM({$dias} BETWEEN 2 AND 7) AS f2,
                SUM({$dias} BETWEEN 8 AND 30) AS f3,
                SUM({$dias} > 30) AS f4,
                AVG({$dias}) AS media,
                MAX({$dias}) AS maior,
                COUNT(*) AS total
            ")->first();

        return [
            'faixas' => [
                ['rotulo' => 'No mesmo dia', 'total' => (int) $l->f0],
                ['rotulo' => '1 dia', 'total' => (int) $l->f1],
                ['rotulo' => '2 a 7 dias', 'total' => (int) $l->f2],
                ['rotulo' => '8 a 30 dias', 'total' => (int) $l->f3],
                ['rotulo' => 'Mais de 30', 'total' => (int) $l->f4],
            ],
            'media' => round((float) $l->media, 1),
            'maior' => (int) $l->maior,
            'total' => (int) $l->total,
        ];
    }

    /**
     * Não autorizadas mais antigas (as presas há mais tempo primeiro).
     *
     * @return list<array{cliente: int, chave: string, referencia: string|null, ultima_consulta: string|null, dias: int}>
     */
    public function pendentesAntigas(?int $clienteId = null, int $limite = 100): array
    {
        return DB::table('history_contingencia_auth')
            ->where('autorizada', 'N')
            ->when($clienteId !== null, fn ($q) => $q->where('fk_c_cliente', $clienteId))
            ->orderBy('referencia')
            ->limit($limite)
            ->get(['fk_c_cliente', 'chave', 'referencia', 'data_consulta_sefaz_bot'])
            ->map(fn (object $l): array => [
                'cliente' => (int) $l->fk_c_cliente,
                'chave' => (string) $l->chave,
                'referencia' => $l->referencia,
                'ultima_consulta' => $l->data_consulta_sefaz_bot,
                'dias' => $l->referencia !== null ? (int) Carbon::parse($l->referencia)->diffInDays(now()->startOfDay()) : 0,
            ])
            ->all();
    }

    public static function taxa(int $parte, int $total): float
    {
        return $total > 0 ? round($parte / $total * 100, 1) : 0.0;
    }

    private function noPeriodo(Periodo $periodo): Builder
    {
        return DB::table('history_contingencia_auth')->whereBetween('referencia', [$periodo->de, $periodo->ate]);
    }
}
