<?php

namespace App\Support;

use App\Models\Auditoria;
use App\Models\RoboConfig;
use App\Models\RoboConfigHistorico;
use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Serviço único de configuração e histórico dos robôs do walle.
 */
class ConfiguracaoRobo
{
    /**
     * Foto dos parâmetros efetivos de cada robô em ciclo.
     *
     * @var array<string, array<string, mixed>>
     */
    private static array $fotos = [];

    /**
     * Retorna se o robô está ligado (env E local).
     * Consulta o banco a cada chamada (sem cache estático).
     */
    public static function ligado(string $robo): bool
    {
        return self::permissaoEnv($robo) && self::ativoLocal($robo);
    }

    /**
     * Retorna se a chave local 'ativo' está ligada no banco.
     */
    public static function ativoLocal(string $robo): bool
    {
        $config = RoboConfig::where('robo', $robo)->where('parametro', 'ativo')->first();

        return $config !== null && filter_var($config->valor, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Retorna se a permissão do .env (WALLE_*_ATIVO) está ligada.
     */
    public static function permissaoEnv(string $robo): bool
    {
        return (bool) config("walle.{$robo}.ativo", false);
    }

    /**
     * Retorna o motivo de estar inativo: 'env' quando o .env desliga, 'local' quando só a chave local desliga, null se ligado.
     */
    public static function motivoInativo(string $robo): ?string
    {
        if (! self::permissaoEnv($robo)) {
            return 'env';
        }
        if (! self::ativoLocal($robo)) {
            return 'local';
        }

        return null;
    }

    /**
     * Retorna o valor efetivo de um parâmetro: persistido em robo_config convertido pelo tipo do catálogo > padrão do config.
     * Para segredos, retorna null.
     */
    public static function efetivo(string $robo, string $parametro): mixed
    {
        // Segredos nunca são devolvidos
        if (self::ehSegredo($parametro)) {
            return null;
        }

        // Dentro de um ciclo, vale a foto tirada no início dele
        if (isset(self::$fotos[$robo]) && array_key_exists($parametro, self::$fotos[$robo])) {
            return self::$fotos[$robo][$parametro];
        }

        return self::lerEfetivo($robo, $parametro);
    }

    /**
     * Executa um ciclo do robô com os parâmetros congelados: uma mudança feita durante o ciclo só vale no seguinte.
     * A foto é descartada no fim, mesmo com exceção; ciclo aninhado do mesmo robô reaproveita a foto de fora.
     *
     * @template T
     *
     * @param  Closure(): T  $ciclo
     * @return T
     */
    public static function noCiclo(string $robo, Closure $ciclo): mixed
    {
        if (isset(self::$fotos[$robo])) {
            return $ciclo();
        }

        $foto = [];
        foreach (array_keys(self::catalogo()[$robo] ?? []) as $parametro) {
            if (! self::ehSegredo($parametro)) {
                $foto[$parametro] = self::lerEfetivo($robo, $parametro);
            }
        }
        self::$fotos[$robo] = $foto;

        try {
            return $ciclo();
        } finally {
            unset(self::$fotos[$robo]);
        }
    }

    /**
     * Lê o valor efetivo no banco/catálogo/config, sem passar pela foto do ciclo.
     */
    private static function lerEfetivo(string $robo, string $parametro): mixed
    {
        $persistido = self::persistido($robo, $parametro);
        if ($persistido !== null) {
            return $persistido;
        }

        // Sem persistência: config do walle.php (.env) quando existe
        $doConfig = config("walle.{$robo}.{$parametro}");
        if ($doConfig !== null) {
            return $doConfig;
        }

        // Por fim: default do catálogo
        return self::catalogo()[$robo][$parametro]['default'] ?? null;
    }

    /**
     * Retorna o valor persistido bruto em robo_config (null se não há linha).
     * Reconverte pelo tipo do catálogo.
     */
    public static function persistido(string $robo, string $parametro): mixed
    {
        $config = RoboConfig::where('robo', $robo)->where('parametro', $parametro)->first();
        if ($config === null || $config->valor === null) {
            return null;
        }

        return self::converterPeloTipo($robo, $parametro, $config->valor);
    }

    /**
     * Retorna o valor de um parâmetro (compatibilidade: delega a efetivo()).
     */
    public static function parametro(string $robo, string $chave, mixed $default = null): mixed
    {
        $valor = self::efetivo($robo, $chave);

        return $valor !== null ? $valor : $default;
    }

    /**
     * Retorna o catálogo completo de todos os robôs.
     *
     * @return array<string, array<string, array{default: mixed, unidade: string, fronteira: array<string, mixed>, requer_decisao_usuario?: bool}>>
     */
    public static function catalogo(): array
    {
        return ParametroCatalogo::todos();
    }

    /**
     * Grava a alteração de um parâmetro com histórico e auditoria.
     */
    public static function gravar(string $robo, string $parametro, mixed $valor, string $ator): void
    {
        if (trim($ator) === '') {
            throw new \InvalidArgumentException('Ator obrigatório');
        }

        // Parâmetro deve existir no catálogo
        $catalogo = self::catalogo();
        if (! isset($catalogo[$robo][$parametro])) {
            throw new \InvalidArgumentException('Parâmetro não existe no catálogo');
        }

        if ($parametro === 'ativo') {
            throw new \LogicException('Use definirAtivo() para alterar o parâmetro ativo');
        }

        // Segredos não são graváveis
        if (self::ehSegredo($parametro)) {
            throw new \LogicException('Parâmetro é segredo; use .env');
        }

        self::validarFronteira($robo, $parametro, $valor);

        DB::transaction(function () use ($robo, $parametro, $valor, $ator) {
            $existente = RoboConfig::where('robo', $robo)
                ->where('parametro', $parametro)
                ->lockForUpdate()
                ->first();

            // valor_anterior: se não há linha, usa o efetivo anterior (padrão do catálogo/config)
            $valorAnteriorEfetivo = $existente !== null
                ? $existente->valor
                : self::valorPadraoCatalogo($robo, $parametro);
            $valorAnteriorSanitizado = ($existente !== null) ? $existente->valor_sanitizado : RoboConfig::sanitizar($valorAnteriorEfetivo);

            $valorTexto = is_array($valor) ? json_encode($valor) : (string) $valor;
            $valorNovoSanitizado = RoboConfig::sanitizar($valorTexto);

            RoboConfig::updateOrCreate(
                ['robo' => $robo, 'parametro' => $parametro],
                ['valor' => $valorTexto, 'valor_sanitizado' => $valorNovoSanitizado]
            );

            $ip = app()->runningInConsole() ? null : request()->ip();

            RoboConfigHistorico::create([
                'robo' => $robo,
                'parametro' => $parametro,
                'acao' => 'alterar',
                'valor_anterior_sanitizado' => $valorAnteriorSanitizado,
                'valor_novo_sanitizado' => $valorNovoSanitizado,
                'ator' => $ator,
                'ip' => $ip,
            ]);

            Auditoria::registrar('robo.config.alterar', null, [
                'robo' => $robo,
                'parametro' => $parametro,
                'valor_anterior_sanitizado' => $valorAnteriorSanitizado,
                'valor_novo_sanitizado' => $valorNovoSanitizado,
                'ator' => $ator,
            ]);
        });
    }

    /**
     * Ativa ou desativa um robô gravando histórico com ação 'ativar' ou 'desativar'.
     * NÃO altera config() do .env (corte de segurança).
     * Valida o robô contra o catálogo e lê a linha existente com lockForUpdate() dentro da transação.
     */
    public static function definirAtivo(string $robo, bool $ativo, string $ator): void
    {
        if (trim($ator) === '') {
            throw new \InvalidArgumentException('Ator obrigatório');
        }

        // Valida robô contra o catálogo
        $catalogo = self::catalogo();
        if (! isset($catalogo[$robo])) {
            throw new \LogicException('Robô desconhecido');
        }

        DB::transaction(function () use ($robo, $ativo, $ator) {
            $existente = RoboConfig::where('robo', $robo)
                ->where('parametro', 'ativo')
                ->lockForUpdate()
                ->first();

            $valorAnteriorEfetivo = $existente !== null
                ? $existente->valor
                : 'false';
            $valorAnteriorSanitizado = ($existente !== null) ? $existente->valor_sanitizado : RoboConfig::sanitizar($valorAnteriorEfetivo);

            $valorTexto = $ativo ? 'true' : 'false';
            $valorNovoSanitizado = $valorTexto;

            RoboConfig::updateOrCreate(
                ['robo' => $robo, 'parametro' => 'ativo'],
                ['valor' => $valorTexto, 'valor_sanitizado' => $valorNovoSanitizado]
            );

            // NÃO faz config(["walle.{$robo}.ativo" => $ativo]) - corte de segurança do .env permanece

            $acao = $ativo ? 'ativar' : 'desativar';
            $ip = app()->runningInConsole() ? null : request()->ip();

            RoboConfigHistorico::create([
                'robo' => $robo,
                'parametro' => 'ativo',
                'acao' => $acao,
                'valor_anterior_sanitizado' => $valorAnteriorSanitizado,
                'valor_novo_sanitizado' => $valorNovoSanitizado,
                'ator' => $ator,
                'ip' => $ip,
            ]);

            Auditoria::registrar("robo.config.{$acao}", null, [
                'robo' => $robo,
                'parametro' => 'ativo',
                'valor_anterior_sanitizado' => $valorAnteriorSanitizado,
                'valor_novo_sanitizado' => $valorNovoSanitizado,
                'ator' => $ator,
            ]);
        });
    }

    /**
     * Confere se a chave é segredo (por segmento).
     */
    private static function ehSegredo(string $parametro): bool
    {
        $segredos = ['senha', 'token', 'certificado', 'pfx', 'chave', 'secret', 'password'];

        // Casa por segmento (erp.senha, api_token); "pausa_entre_chaves" não é segredo.
        $segmentos = preg_split('/[._\-]+/', strtolower($parametro)) ?: [];

        return array_intersect($segmentos, $segredos) !== [];
    }

    /**
     * Valida min/max e tipo com base no catálogo (int, float, bool, string, array).
     */
    private static function validarFronteira(string $robo, string $parametro, mixed $valor): void
    {
        $catalogo = self::catalogo();

        if (! isset($catalogo[$robo][$parametro])) {
            return;
        }

        $info = $catalogo[$robo][$parametro];
        $fronteira = $info['fronteira'];

        if (isset($fronteira['segredo']) && $fronteira['segredo'] === true) {
            throw new \LogicException('Parâmetro é segredo; use .env');
        }

        $tipo = $fronteira['tipo'] ?? null;

        switch ($tipo) {
            case 'int':
                $num = filter_var($valor, FILTER_VALIDATE_INT);
                if ($num === false) {
                    throw new \InvalidArgumentException('Tipo inválido');
                }
                if (isset($fronteira['min']) && $num < $fronteira['min']) {
                    throw new \InvalidArgumentException('Fora da fronteira');
                }
                if (isset($fronteira['max']) && $num > $fronteira['max']) {
                    throw new \InvalidArgumentException('Fora da fronteira');
                }
                break;

            case 'float':
                $num = filter_var($valor, FILTER_VALIDATE_FLOAT);
                if ($num === false) {
                    throw new \InvalidArgumentException('Tipo inválido');
                }
                if (isset($fronteira['min']) && $num < $fronteira['min']) {
                    throw new \InvalidArgumentException('Fora da fronteira');
                }
                if (isset($fronteira['max']) && $num > $fronteira['max']) {
                    throw new \InvalidArgumentException('Fora da fronteira');
                }
                break;

            case 'bool':
                if (! is_bool($valor)) {
                    throw new \InvalidArgumentException('Tipo inválido');
                }
                break;

            case 'string':
                if (! is_string($valor)) {
                    throw new \InvalidArgumentException('Tipo inválido');
                }
                break;

            case 'array':
                if (! is_array($valor)) {
                    throw new \InvalidArgumentException('Tipo inválido');
                }
                break;

            default:
                // Tipo desconhecido ou não definido: não valida
                break;
        }
    }

    /**
     * Converte valor texto pelo tipo do catálogo.
     */
    private static function converterPeloTipo(string $robo, string $parametro, string $valorTexto): mixed
    {
        $catalogo = self::catalogo();
        if (! isset($catalogo[$robo][$parametro])) {
            return $valorTexto;
        }

        $tipo = $catalogo[$robo][$parametro]['fronteira']['tipo'] ?? null;

        return match ($tipo) {
            'int' => (int) $valorTexto,
            'float' => (float) $valorTexto,
            'bool' => filter_var($valorTexto, FILTER_VALIDATE_BOOLEAN),
            'array' => json_decode($valorTexto, true) ?? [],
            default => $valorTexto,
        };
    }

    /**
     * Retorna o valor padrão do catálogo (ou config) como string para histórico.
     */
    private static function valorPadraoCatalogo(string $robo, string $parametro): string
    {
        $catalogo = self::catalogo();
        if (isset($catalogo[$robo][$parametro])) {
            $default = $catalogo[$robo][$parametro]['default'];

            return is_array($default) ? json_encode($default) : (string) $default;
        }

        $configValor = config("walle.{$robo}.{$parametro}");

        return is_array($configValor) ? json_encode($configValor) : (string) $configValor;
    }
}
