<?php

use App\Http\Controllers\LandingFiscalController;
use App\Http\Controllers\SairController;
use App\Livewire\Autorizadas;
use App\Livewire\Concorrentes;
use App\Livewire\Conhecimento;
use App\Livewire\Contingencias;
use App\Livewire\Entrar;
use App\Livewire\Painel;
use App\Livewire\Sactus;
use App\Livewire\Saude;
use Illuminate\Support\Facades\Route;

Route::livewire('/entrar', Entrar::class)->middleware('guest')->name('entrar');

Route::middleware('auth')->group(function () {
    Route::livewire('/', Painel::class)->name('painel');
    Route::livewire('/contingencias', Contingencias::class)->name('contingencias');
    Route::livewire('/autorizadas', Autorizadas::class)->name('autorizadas');
    Route::livewire('/sactus', Sactus::class)->name('sactus');
    Route::livewire('/concorrentes', Concorrentes::class)->name('concorrentes');
    Route::livewire('/conhecimento', Conhecimento::class)->middleware('can:admin')->name('conhecimento');
    Route::get('/landing/fiscal', LandingFiscalController::class)->name('landing.fiscal');
    Route::livewire('/saude', Saude::class)->name('saude');
    Route::post('/sair', SairController::class)->name('sair');
});
