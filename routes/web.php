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

    // UC-03 : logements et décompteurs (tranche 2). scopeBindings : un logement n'est trouvé
    // que dans sa propre maison.
    Route::livewire('/maisons/{house}/logements/nouveau', 'app.logements.nouveau')->name('logements.create');
    Route::livewire('/maisons/{house}/logements/{unit}', 'app.logements.detail')
        ->name('logements.show')
        ->scopeBindings();

    // UC-04 à UC-06 : locataires, entrée et sortie avec relevé (tranche 3).
    Route::livewire('/maisons/{house}/logements/{unit}/entree', 'app.logements.entree')
        ->name('logements.entry')
        ->scopeBindings();
    Route::livewire('/maisons/{house}/logements/{unit}/sortie', 'app.logements.sortie')
        ->name('logements.exit')
        ->scopeBindings();

    // UC-07 à UC-12 : saisie d'un mois, relevés, remplacement, factures (tranche 4).
    Route::livewire('/maisons/{house}/periodes/{mois}', 'app.periodes.saisie')
        ->name('periodes.show')
        ->where('mois', '\d{4}-\d{2}');

    Route::livewire('/locataires', 'app.locataires.index')->name('locataires.index');
    Route::livewire('/locataires/nouveau', 'app.locataires.nouveau')->name('locataires.create');
    Route::livewire('/locataires/{tenant}', 'app.locataires.detail')->name('locataires.show');
});
