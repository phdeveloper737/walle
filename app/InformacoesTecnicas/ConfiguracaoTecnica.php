<?php

namespace App\InformacoesTecnicas;

use App\Models\InformacaoTecnicaPainelContingencias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Configuração técnica de acesso ao servidor do cliente (informacao_tecnica_painel_contingencias), usada pelo
 * Contingência BOT. Substitui system/consultorias/config do sistema antigo.
 *
 * Só grava as colunas de CAMPOS e SEGREDOS (lista fixa: o sets.php do 220 aceitava qualquer nome de coluna).
 * O Help Desk (192.168.10.254) só é lido; tudo o que se grava fica no banco local `walle`.
 *
 * Obrigatórios (o 220 marcava tudo como `required`, mas não validava nada): tipo de servidor, tipo de PDV,
 * cliente ativo, banco separado, IP externo, servidor e banco do MySQL e as portas HTTP, MySQL e SSH. Os campos do
 * Docker só são obrigatórios quando o servidor é "Docker com banco JM Open" (DO). Portas: inteiro de 1 a 65535.
 */
class ConfiguracaoTecnica
{
    /** Rótulo de cada campo editável sem segredo (a ordem é a da tela). */
    public const CAMPOS = [
        'ativo_painel_contingencias' => 'Cliente ativo no painel',
        'tips_server' => 'Tipo de servidor',
        'tips_pdv' => 'Tipo de PDV',
        'ip_docker_principal' => 'Servidor principal (Docker)',
        'banco_separado_docker' => 'Banco separado (Docker)',
        'ip_container_docker' => 'IP do contêiner (Docker)',
        'porta_http_docker' => 'Porta HTTP (Docker)',
        'porta_mysql_docker' => 'Porta MySQL (Docker)',
        'porta_ssh_docker' => 'Porta SSH (Docker)',
        'porta_ssh_container_docker' => 'Porta SSH do contêiner (Docker)',
        'ip_externo_servidor' => 'IP externo do servidor',
        'servidor_mysql' => 'Servidor MySQL',
        'porta_http' => 'Porta HTTP',
        'porta_mysql' => 'Porta MySQL',
        'porta_ssh' => 'Porta SSH',
        'banco_mysql' => 'Banco MySQL',
    ];

    /** Segredos: nunca vão para a tela; só são trocados quando vêm preenchidos. */
    public const SEGREDOS = [
        'senha_ssh_docker' => 'Senha SSH (Docker)',
        'senha_mysql' => 'Senha MySQL',
        'senha_ssh' => 'Senha SSH',
    ];

    public const TIPOS_SERVIDOR = ['LL' => 'Local com banco JM Local', 'LO' => 'Local com banco JM Open', 'DO' => 'Docker com banco JM Open', 'VO' => 'Versatus com banco JM Open'];

    public const TIPOS_PDV = ['L' => 'Linux', 'V' => 'Versatus', 'A' => 'Ambos'];

    public const SERVIDORES_DOCKER = ['192.168.10.244', '192.168.10.106'];

    /**
     * Regras de validação; $prefixo é o caminho do campo no componente (ex.: 'form.').
     *
     * @return array<string, list<string>>
     */
    public function regras(string $prefixo = ''): array
    {
        $docker = "required_if:{$prefixo}tips_server,DO";
        $porta = ['integer', 'between:1,65535'];

        $regras = [
            'ativo_painel_contingencias' => ['required', 'in:S,N'],
            'tips_server' => ['required', 'in:'.implode(',', array_keys(self::TIPOS_SERVIDOR))],
            'tips_pdv' => ['required', 'in:'.implode(',', array_keys(self::TIPOS_PDV))],
            'ip_docker_principal' => [$docker, 'nullable', 'in:'.implode(',', self::SERVIDORES_DOCKER)],
            'banco_separado_docker' => ['required', 'in:S,N'],
            'ip_container_docker' => [$docker, 'nullable', 'ip', 'max:50'],
            'porta_http_docker' => [$docker, 'nullable', ...$porta],
            'porta_mysql_docker' => [$docker, 'nullable', ...$porta],
            'porta_ssh_docker' => [$docker, 'nullable', ...$porta],
            'porta_ssh_container_docker' => [$docker, 'nullable', ...$porta],
            'ip_externo_servidor' => ['required', 'ip', 'max:50'],
            'servidor_mysql' => ['required', 'string', 'max:50'],
            'porta_http' => ['required', ...$porta],
            'porta_mysql' => ['required', ...$porta],
            'porta_ssh' => ['required', ...$porta],
            'banco_mysql' => ['required', 'string', 'max:50'],
        ];

        foreach (array_keys(self::SEGREDOS) as $segredo) {
            $regras[$segredo] = ['nullable', 'string', 'max:100'];
        }

        $comPrefixo = [];

        foreach ($regras as $campo => $regra) {
            $comPrefixo[$prefixo.$campo] = $regra;
        }

        return $comPrefixo;
    }

    /** @return array<string, string> */
    public function mensagens(): array
    {
        return [
            'required' => 'Preencha :attribute.',
            'required_if' => 'Preencha :attribute (obrigatório para servidor Docker).',
            'in' => 'Escolha uma opção válida em :attribute.',
            'ip' => ':attribute deve ser um IP válido, como 192.168.10.5.',
            'integer' => ':attribute deve ser uma porta de 1 a 65535.',
            'between' => ':attribute deve ser uma porta de 1 a 65535.',
            'max' => ':attribute passa de :max caracteres.',
            'string' => ':attribute está inválido.',
        ];
    }

    /** @return array<string, string> */
    public function rotulos(string $prefixo = ''): array
    {
        $rotulos = [];

        foreach ([...self::CAMPOS, ...self::SEGREDOS] as $campo => $rotulo) {
            $rotulos[$prefixo.$campo] = mb_strtolower($rotulo);
        }

        return $rotulos;
    }

    /**
     * Grava a configuração do cliente. Colunas fora da lista fixa são ignoradas; senha em branco mantém a atual.
     *
     * @param  array<string, mixed>  $dados
     *
     * @throws ValidationException
     */
    public function salvar(int $clienteId, array $dados): void
    {
        $dados = $this->somenteConhecidos($dados);

        Validator::make($dados, $this->regras(), $this->mensagens(), $this->rotulos())->validate();

        $linha = $this->linhaDoCliente($clienteId);
        $linha->fill($this->paraGravar($dados));
        $linha->ultima_atualizacao = now();
        $linha->save();
    }

    /**
     * Nomes dos campos que este salvar mudaria (para a auditoria; nunca devolve valores).
     *
     * @param  array<string, mixed>  $dados
     * @return list<string>
     */
    public function camposAlterados(int $clienteId, array $dados): array
    {
        $atual = InformacaoTecnicaPainelContingencias::find($clienteId);
        $alterados = [];

        foreach ($this->paraGravar($this->somenteConhecidos($dados)) as $campo => $valor) {
            if ($atual === null || (string) $atual->getAttribute($campo) !== (string) $valor) {
                $alterados[] = $campo;
            }
        }

        return $alterados;
    }

    /**
     * Lê o cadastro do cliente no Help Desk (somente SELECT) e grava só no banco local, mantendo o que a tela
     * já definiu (tips_server, tips_pdv e emissao_cupons). Cliente novo começa com DO e A, como no 220.
     *
     * @throws ValidationException quando o Help Desk não tem o cadastro técnico do cliente
     */
    public function importarDoHelpDesk(int $clienteId): void
    {
        $origem = DB::connection('help')->table('c_cliente as c')
            ->join('informacao_tecnica_dataserver as i', 'i.fk_c_cliente', '=', 'c.id')
            ->where('c.id', $clienteId)
            ->select([
                'c.id', 'c.cnpj', 'c.razao_social', 'c.nome_fantasia',
                'i.loja', 'i.servidor_docker', 'i.porta_ssh_docker', 'i.porta_mysql_docker', 'i.key_rsa_amazon',
                'i.senha_ssh_docker', 'i.banco_mysql_docker', 'i.senha_mysql_docker', 'i.usuario_mysql_docker',
                'i.porta_mysql', 'i.banco_mysql', 'i.senha_mysql', 'i.senha_ssh', 'i.ip_publico',
            ])
            ->first();

        if ($origem === null) {
            throw ValidationException::withMessages([
                'importar' => 'O Help Desk não tem o cadastro técnico deste cliente. Confira o cadastro e tente de novo.',
            ]);
        }

        $campo = fn (string $nome): string => trim((string) ($origem->{$nome} ?? ''));
        $portaMysql = $campo('porta_mysql_docker') === '' ? $campo('porta_mysql') : $campo('porta_mysql_docker');

        $linha = $this->linhaDoCliente($clienteId);

        $linha->fill([
            'id_loja' => $campo('loja'),
            'cnpj' => $campo('cnpj'),
            'razao_social' => $campo('razao_social'),
            'nome_fantasia' => $campo('nome_fantasia'),
            'tips_server' => $this->preenchido($linha->tips_server) ?? 'DO',
            'tips_pdv' => $this->preenchido($linha->tips_pdv) ?? 'A',
            'ip_docker_principal' => $campo('servidor_docker'),
            'banco_separado_docker' => $campo('porta_mysql_docker') === '3306' ? 'N' : 'S',
            'ip_container_docker' => $campo('servidor_docker'),
            'porta_http_docker' => $campo('key_rsa_amazon'),
            'porta_mysql_docker' => $campo('porta_mysql_docker'),
            'porta_ssh_docker' => $campo('porta_ssh_docker'),
            'porta_ssh_container_docker' => $campo('porta_ssh_docker'),
            'senha_ssh_docker' => $campo('senha_ssh_docker'),
            'banco_mysql_docker' => $campo('banco_mysql_docker'),
            'senha_mysql_docker' => $campo('senha_mysql_docker'),
            'usuario_mysql_docker' => $campo('usuario_mysql_docker'),
            'ip_externo_servidor' => $campo('servidor_docker'),
            'servidor_mysql' => $campo('servidor_docker'),
            'porta_http' => '80',
            'porta_mysql' => $portaMysql,
            'porta_ssh' => '22',
            'banco_mysql' => $campo('banco_mysql'),
            'senha_mysql' => $campo('senha_mysql'),
            'senha_ssh' => $campo('senha_ssh'),
            'ativo_painel_contingencias' => 'S',
            'deletado' => 'N',
            'ip_publico' => $campo('ip_publico'),
        ]);
        $linha->ultima_atualizacao = now();
        $linha->save();
    }

    private function linhaDoCliente(int $clienteId): InformacaoTecnicaPainelContingencias
    {
        $linha = InformacaoTecnicaPainelContingencias::firstOrNew(['fk_c_cliente' => $clienteId]);

        if (! $linha->exists) {
            $linha->id = $clienteId;
        }

        return $linha;
    }

    /**
     * @param  array<string, mixed>  $dados
     * @return array<string, mixed>
     */
    private function somenteConhecidos(array $dados): array
    {
        return array_intersect_key($dados, [...self::CAMPOS, ...self::SEGREDOS]);
    }

    /**
     * Valores prontos para a coluna: texto aparado, vazio vira null e senha em branco fica de fora.
     *
     * @param  array<string, mixed>  $dados
     * @return array<string, string|null>
     */
    private function paraGravar(array $dados): array
    {
        $gravar = [];

        foreach ($dados as $campo => $valor) {
            $texto = trim((string) $valor);

            if (array_key_exists($campo, self::SEGREDOS)) {
                if ($texto !== '') {
                    $gravar[$campo] = $texto;
                }

                continue;
            }

            $gravar[$campo] = $texto === '' ? null : $texto;
        }

        return $gravar;
    }

    private function preenchido(?string $valor): ?string
    {
        return $valor === null || trim($valor) === '' ? null : trim($valor);
    }
}
