<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Copia dados do banco "suporte" (sistema antigo) para o banco walle, tabela por tabela,
 * mantendo os ids. Só as tabelas que o walle já tem, com o mesmo nome e estrutura.
 *
 * Apaga o conteúdo da tabela no walle antes de copiar: pode rodar quantas vezes quiser até a virada.
 */
class ImportarSuporte extends Command
{
    protected $signature = 'walle:importar-suporte
                            {tabelas?* : Tabelas a copiar (sem informar: todas as que o walle já usa)}
                            {--lote=2000 : Linhas por lote}';

    protected $description = 'Copia dados do banco suporte (sistema antigo) para o banco walle';

    /**
     * Tabelas do suporte que já existem no walle, na ordem de cópia (pais antes dos filhos).
     * Cada módulo novo acrescenta aqui as tabelas que trouxe.
     */
    public const TABELAS = [
        'a_user_acessos',
        'contingencias_versatus_venda',
        'contingencias_versatus_venda_itens',
        'history_contingencia',
        'history_contingencia_auth',
        'painel_sactus_cfg',
        'sactus_cfg_edicoes',
    ];

    /** Ordem de leitura das tabelas sem coluna id (a cópia em lotes precisa de uma ordem estável). */
    private const ORDEM = [
        'history_contingencia' => 'chave',
        'history_contingencia_auth' => 'chave',
    ];

    public function handle(): int
    {
        $pedidas = $this->argument('tabelas') ?: self::TABELAS;
        $desconhecidas = array_diff($pedidas, self::TABELAS);

        if ($desconhecidas !== []) {
            $this->error('Tabela fora da lista do walle: '.implode(', ', $desconhecidas));

            return self::FAILURE;
        }

        // Mantém a ordem da lista (pais antes dos filhos), mesmo se pedirem em outra ordem
        $tabelas = array_values(array_intersect(self::TABELAS, $pedidas));
        $lote = max(100, (int) $this->option('lote'));

        $this->info('Origem: '.config('database.connections.suporte.host').':'.config('database.connections.suporte.port').'/'.config('database.connections.suporte.database'));

        Schema::disableForeignKeyConstraints();

        try {
            foreach ($tabelas as $tabela) {
                $this->copiar($tabela, $lote);
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        return self::SUCCESS;
    }

    private function copiar(string $tabela, int $lote): void
    {
        $origem = DB::connection('suporte');
        $destino = DB::connection();

        // Só as colunas que existem nos dois lados (o walle pode ter colunas a mais)
        $colunas = array_values(array_intersect(
            Schema::connection('suporte')->getColumnListing($tabela),
            Schema::getColumnListing($tabela),
        ));
        $chave = self::ORDEM[$tabela] ?? (in_array('id', $colunas, true) ? 'id' : $colunas[0]);

        $total = $origem->table($tabela)->count();
        $inicio = microtime(true);

        $destino->table($tabela)->truncate();

        $barra = $this->output->createProgressBar($total);
        $barra->setFormat(" {$tabela}: %current%/%max% [%bar%] %percent:3s%%");

        $linhas = [];

        foreach ($origem->table($tabela)->select($colunas)->orderBy($chave)->lazy($lote) as $linha) {
            $linhas[] = (array) $linha;

            if (count($linhas) >= $lote) {
                $destino->table($tabela)->insert($linhas);
                $barra->advance(count($linhas));
                $linhas = [];
            }
        }

        if ($linhas !== []) {
            $destino->table($tabela)->insert($linhas);
            $barra->advance(count($linhas));
        }

        $barra->finish();
        $this->newLine();
        $this->line(sprintf('  %s: %d linhas em %.1f s', $tabela, $total, microtime(true) - $inicio));
    }
}
