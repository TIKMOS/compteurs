<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// Les routes d'authentification (/login, /register, /forgot-password…) sont fournies par Fortify.

Route::redirect('/', '/maisons');

Route::middleware(['auth', 'verified'])->group(function (): void {
    // Lot 1 : liste des maisons (UC-02). Page d'attente tant que le module n'est pas écrit.
    Route::view('/maisons', 'maisons.index')->name('maisons.index');
});
