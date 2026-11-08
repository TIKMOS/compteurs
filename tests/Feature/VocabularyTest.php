<?php

declare(strict_types=1);

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
