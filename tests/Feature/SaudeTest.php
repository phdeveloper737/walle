<?php

use App\Models\Usuario;
use App\Support\HelpDesk\PermissoesHelpDesk;
use App\Support\HelpDesk\ResultadoPermissoes;

beforeEach(function () {
    $this->actingAs(Usuario::find(criarUsuarioHelpDesk()));
});

/** Troca a conferência real de permissões por um resultado fixo. */
function permissoesFalsas(ResultadoPermissoes $resultado): void
{
    app()->instance(PermissoesHelpDesk::class, new class($resultado) extends PermissoesHelpDesk
    {
        public function __construct(private readonly ResultadoPermissoes $fixo) {}

        public function verificar(): ResultadoPermissoes
        {
            return $this->fixo;
        }
    });
}

it('mostra o Help Desk como somente leitura', function () {
    permissoesFalsas(new ResultadoPermissoes(true));

    $this->get(route('saude'))->assertOk()
        ->assertSee('Help Desk (192.168.10.254)')
        ->assertSee('somente leitura');
});

it('lista só os nomes dos privilégios de escrita do Help Desk', function () {
    permissoesFalsas(new ResultadoPermissoes(false, ['INSERT', 'DELETE']));

    $this->get(route('saude'))->assertOk()
        ->assertSee('INSERT, DELETE')
        ->assertSee('com problema')
        ->assertDontSee('GRANT ');
});

it('avisa que não deu para conferir as permissões do Help Desk', function () {
    permissoesFalsas(ResultadoPermissoes::falha('Não foi possível consultar as permissões do Help Desk. Confira se ele está no ar e tente de novo.'));

    $this->get(route('saude'))->assertOk()
        ->assertSee('Não foi possível conferir as permissões')
        ->assertSee('Não foi possível consultar as permissões do Help Desk');
});
