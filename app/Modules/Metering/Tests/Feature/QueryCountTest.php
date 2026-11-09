<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\StreamedResponse;

// Revue M-6 / M-7 : pas de requêtes répétées par locataire ou par décompteur (N+1) sur les pages
// les plus chargées. Plafonds mesurés sur la démo (4 logements, 7 décompteurs) avec une marge.

test('aucune page lourde ne répète une même requête plus de deux fois', function (string $page, int $max): void {
    $this->seed(DemoSeeder::class);
    $this->actingAs(User::query()->sole());
    $url = '/maisons/' . House::query()->sole()->getRouteKey() . $page;

    DB::flushQueryLog();
    DB::enableQueryLog();
    $response = $this->get($url)->assertOk();
    if ($response->baseResponse instanceof StreamedResponse) {
        ob_start();
        $response->baseResponse->sendContent();
        ob_end_clean();
    }
    $queries = collect(DB::getQueryLog());
    DB::disableQueryLog();

    $repeated = $queries->countBy('query')->filter(fn (int $n): bool => $n > 2);
    expect($repeated->all())->toBe([])
        ->and($queries->count())->toBeLessThanOrEqual($max);
})->with([
    'saisie du mois' => ['/periodes/2026-11', 12],
    'résultat d\'un mois clôturé' => ['/periodes/2026-10/recapitulatif', 40],
    'tableau de bord' => ['/tableau-de-bord', 30],
    'paiements' => ['/paiements', 12],
    'export Excel' => ['/historique.xlsx', 12],
]);
