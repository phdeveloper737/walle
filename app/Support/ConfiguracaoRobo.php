<?php

namespace App\Support;

use App\Models\Auditoria;
use App\Models\RoboConfig;
use App\Models\RoboConfigHistorico;

/**
 * Serviço único de configuração e histórico dos robôs do walle.
 */
class ConfiguracaoRobo
{
    /**
     * Retorna se o robô está ligado.
     */
    public static function ligado(string $robo): bool
    {
        return (bool) config("walle.{$robo}.ativo", false);
    }

    /**
     * Retorna o valor de um parâmetro do robô.
     */
    public static function parametro(string $robo, string $chave, mixed $default = null): mixed
    {
        return config("walle.{$robo}.{$chave}", $default);
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

        if (self::ehSegredo($parametro)) {
            throw new \LogicException('Parâmetro é segredo; use .env');
        }

        self::validarFronteira($robo, $parametro, $valor);

        $valorTexto = is_array($valor) ? json_encode($valor) : (string) $valor;

        $existente = RoboConfig::where('robo', $robo)->where('parametro', $parametro)->first();
        $valorAnterior = $existente?->valor;
        $valorAnteriorSanitizado = $existente?->valor_sanitizado;

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
            'valor_anterior' => $valorAnterior,
            'valor_novo' => $valorTexto,
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
    }

    /**
     * Ativa ou desativa um robô gravando histórico com ação 'ativar' ou 'desativar'.
     */
    public static function definirAtivo(string $robo, bool $ativo, string $ator): void
    {
        if (trim($ator) === '') {
            throw new \InvalidArgumentException('Ator obrigatório');
        }

        $existente = RoboConfig::where('robo', $robo)->where('parametro', 'ativo')->first();
        $valorAnterior = $existente?->valor;
        $valorAnteriorSanitizado = $existente?->valor_sanitizado;

        $valorTexto = $ativo ? 'true' : 'false';
        $valorNovoSanitizado = $valorTexto;

        RoboConfig::updateOrCreate(
            ['robo' => $robo, 'parametro' => 'ativo'],
            ['valor' => $valorTexto, 'valor_sanitizado' => $valorNovoSanitizado]
        );

        config(["walle.{$robo}.ativo" => $ativo]);

        $acao = $ativo ? 'ativar' : 'desativar';
        $ip = app()->runningInConsole() ? null : request()->ip();

        RoboConfigHistorico::create([
            'robo' => $robo,
            'parametro' => 'ativo',
            'acao' => $acao,
            'valor_anterior' => $valorAnterior,
            'valor_novo' => $valorTexto,
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
    }

    /**
     * Confere se a chave é segredo.
     */
    private static function ehSegredo(string $parametro): bool
    {
        $segredos = ['senha', 'token', 'certificado', 'pfx', 'chave', 'secret', 'password'];

        // Casa por segmento (erp.senha, api_token); "pausa_entre_chaves" não é segredo.
        $segmentos = preg_split('/[._\-]+/', strtolower($parametro)) ?: [];

        return array_intersect($segmentos, $segredos) !== [];
    }

    /**
     * Valida min/max e tipo com base no catálogo.
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

        if ($tipo === 'int') {
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
        }
    }
}
