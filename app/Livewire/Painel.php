<?php

namespace App\Livewire;

use App\Models\Usuario;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Painel')]
class Painel extends Component
{
    public function render(): View
    {
        $hora = (int) now()->format('G');

        /** @var Usuario $usuario */
        $usuario = Auth::user();

        return view('livewire.painel', [
            'saudacao' => match (true) {
                $hora < 12 => 'Bom dia',
                $hora < 18 => 'Boa tarde',
                default => 'Boa noite',
            },
            'nome' => $usuario->primeiroNome(),
        ]);
    }
}
