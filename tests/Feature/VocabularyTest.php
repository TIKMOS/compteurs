<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\Occupancy;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Metering\Actions\CreateTenantAccessLink;
use App\Modules\Metering\Queries\BuildTenantStatement;
use Database\Seeders\DemoSeeder;
use Symfony\Component\Finder\Finder;

// T-54 / ENF-15 : « répartition des charges », « participation aux frais » ; jamais de vente
// ni d'achat dans les textes affichés. Seuls l'avertissement de conformité (config/legal.php)
// et les conditions d'utilisation citent la revente, pour l'interdire.

test('aucun texte affiché ne parle de vente ni d\'achat', function (): void {
    $forbidden = '/\b(vente|ventes|vendre|vendu|revente|revendre|achat|achats|acheter|acheté)\b/iu';
    $offenders = [];

    $files = Finder::create()->files()
        ->in([base_path('lang'), resource_path('views')])
        ->notPath('pages/conditions-utilisation')
        ->name(['*.php', '*.json']);

    foreach ($files as $file) {
        foreach (preg_split('/\R/', $file->getContents()) as $number => $line) {
            if (preg_match($forbidden, $line) === 1) {
                $offenders[] = $file->getRelativePathname() . ':' . ($number + 1) . ' → ' . trim($line);
            }
        }
    }

    expect($offenders)->toBe([]);
});

test('côté locataire, ni vente, ni achat, ni facture : portail, fiches PDF, message WhatsApp', function (): void {
    $this->seed(DemoSeeder::class);
    $owner = User::query()->sole();
    $forbidden = '/\b(vente|ventes|vendre|vendu|revente|achat|achats|acheter|acheté|facture|factures|facturé|facturée|facturation)\b/iu';
    $texts = [];

    foreach (Tenant::all() as $tenant) {
        $texts["portail {$tenant->name}"] = strip_tags($this->get(app(CreateTenantAccessLink::class)->execute($tenant, $owner))->assertOk()->getContent());
    }
    foreach (Occupancy::all() as $occupancy) {
        foreach (BillingPeriod::query()->where('status', 'closed')->get() as $period) {
            $statement = app(BuildTenantStatement::class)->execute($period, $occupancy);
            if ($statement->utilities === []) {
                continue;
            }
            $key = "{$occupancy->tenant->name} {$period->month->format('Y-m')}";
            $texts["fiche PDF {$key}"] = strip_tags(view('pdf.fiche-locataire', ['s' => $statement])->render());
            $texts["WhatsApp {$key}"] = $statement->whatsappMessage();
        }
    }
    $texts['lien expiré'] = strip_tags(view('pages.lien-expire')->render());

    expect(count($texts))->toBeGreaterThan(10);
    $offenders = [];
    foreach ($texts as $where => $text) {
        if (preg_match_all($forbidden, $text, $m) > 0) {
            $offenders[] = $where . ' → ' . implode(', ', array_unique($m[0]));
        }
    }
    expect($offenders)->toBe([]);
});

test('la fiche de sortie ne contient pas « facture »', function (): void {
    $view = (string) file_get_contents(resource_path('views/pdf/fiche-sortie.blade.php'));
    expect(preg_match('/factur/iu', $view))->toBe(0);
});
