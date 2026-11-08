<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Les routes d'authentification (/login, /register, /forgot-password…) sont fournies par Fortify.

Route::redirect('/', '/maisons');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // UC-02, UC-22 : maisons, compteurs principaux, mode de calcul (lot 1, tranche 1).
    Route::livewire('/maisons', 'app.maisons.index')->name('maisons.index');
    Route::livewire('/maisons/nouvelle', 'app.maisons.nouveau')->name('maisons.create');
    Route::livewire('/maisons/{house}', 'app.maisons.detail')->name('maisons.show');
    Route::livewire('/maisons/{house}/modifier', 'app.maisons.modifier')->name('maisons.edit');
    Route::livewire('/maisons/{house}/parametres', 'app.maisons.parametres')->name('maisons.settings');
});
