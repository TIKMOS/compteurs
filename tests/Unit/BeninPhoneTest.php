<?php

declare(strict_types=1);

use App\Shared\Support\BeninPhone;

test('normalise les écritures courantes d\'un numéro béninois', function (string $input): void {
    expect(BeninPhone::normalize($input))->toBe('+2290197000000');
})->with([
    'format local' => '0197000000',
    'avec espaces' => '01 97 00 00 00',
    'avec points' => '01.97.00.00.00',
    'international' => '+229 01 97 00 00 00',
    'préfixe 00' => '00229 0197000000',
    'ancien numéro à 8 chiffres' => '97 00 00 00',
    'ancien numéro avec indicatif' => '+229 97 00 00 00',
]);

test('refuse ce qui n\'est pas un numéro béninois', function (?string $input): void {
    expect(BeninPhone::normalize($input))->toBeNull();
})->with([
    'vide' => '',
    'null' => null,
    'trop court' => '0197',
    'mauvais préfixe' => '0297000000',
    'numéro français' => '+33 6 12 34 56 78',
]);
