<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Les routes d'authentification (/login, /register, /forgot-password…) sont fournies par Fortify.

Route::redirect('/', '/maisons');

// EF-51 : conditions d'utilisation, publiques ; acceptation demandée avant tout accès (H-36).
Route::view('/conditions-utilisation', 'pages.conditions-utilisation')->name('legal.terms');
Route::livewire('/conditions-utilisation/accepter', 'app.legal.accepter')
    ->middleware('auth')
    ->name('legal.accept');

Route::middleware(['auth', 'verified', 'terms'])->group(function (): void {
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

    // UC-13, UC-14 : résultat du mois et clôture (tranche 5).
    Route::livewire('/maisons/{house}/periodes/{mois}/recapitulatif', 'app.periodes.recapitulatif')
        ->name('periodes.summary')
        ->where('mois', '\d{4}-\d{2}');

    // EF-37 : fiche PDF d'un locataire pour un mois clôturé (lot 2, tranche 2).
    Route::get('/maisons/{house}/periodes/{mois}/fiches/{occupancy}', App\Modules\Metering\Http\Controllers\TenantStatementPdfController::class)
        ->name('periodes.statement')
        ->where(['mois' => '\d{4}-\d{2}', 'occupancy' => '\d+']);

    // UC-18 : paiements, impayés, remboursements de crédit (lot 2, tranche 3).
    Route::livewire('/maisons/{house}/paiements', 'app.paiements.index')->name('paiements.index');

    Route::livewire('/locataires', 'app.locataires.index')->name('locataires.index');
    Route::livewire('/locataires/nouveau', 'app.locataires.nouveau')->name('locataires.create');
    Route::livewire('/locataires/{tenant}', 'app.locataires.detail')->name('locataires.show');
});
