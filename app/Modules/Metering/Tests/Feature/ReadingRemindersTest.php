<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Notifications\MonthEndReadingsReminder;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Notification;

// Lot 3, tranche 3 — UC-20 : rappel de relevé le dernier jour du mois, relance le 3.

beforeEach(function (): void {
    Notification::fake();
    CarbonImmutable::setTestNow('2026-11-30 08:00:00');
    $this->owner = User::factory()->create(['name' => 'Ama Dossou']);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $this->elec = $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1000']);
    $this->water = $unit->subMeters()->create(['utility' => Utility::Water, 'installed_on' => '2026-10-01', 'install_index' => '100']);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('dernier jour du mois : rappel au propriétaire, avec le nombre de relevés à faire', function (): void {
    $this->artisan('compteurs:rappel-releves', ['--date' => '2026-11-30'])
        ->expectsOutputToContain('1 rappel(s) envoyé(s) pour novembre 2026.')
        ->assertSuccessful();

    Notification::assertSentTo($this->owner, MonthEndReadingsReminder::class, function (MonthEndReadingsReminder $n): bool {
        $mail = $n->toMail($this->owner);

        return ! $n->isFollowUp
            && $n->houses[0]['missing'] === 2
            && $mail->subject === 'Relevés de fin de mois : novembre 2026'
            && in_array('• Cotonou Fidjrossè : 2 relevés à saisir', $mail->introLines, true)
            && $mail->actionUrl === route('periodes.show', [$this->house, '2026-11']);
    });
});

test('relance le 3 pour le mois précédent, seulement s\'il manque des relevés', function (): void {
    $november = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
    app(RecordMonthEndReading::class)->execute($november, $this->elec, '1100');

    $this->artisan('compteurs:rappel-releves', ['--date' => '2026-12-03'])->assertSuccessful();

    Notification::assertSentTo($this->owner, MonthEndReadingsReminder::class, fn (MonthEndReadingsReminder $n): bool => $n->isFollowUp
        && $n->month->format('Y-m') === '2026-11'
        && $n->houses[0]['missing'] === 1
        && $n->toMail($this->owner)->subject === 'Relevés manquants : novembre 2026');

    // Tout est saisi : plus de relance.
    Notification::fake();
    app(RecordMonthEndReading::class)->execute($november, $this->water, '110');
    $this->artisan('compteurs:rappel-releves', ['--date' => '2026-12-03'])->assertSuccessful();
    Notification::assertNothingSent();
});

test('les autres jours, rien n\'est envoyé', function (): void {
    $this->artisan('compteurs:rappel-releves', ['--date' => '2026-11-15'])
        ->expectsOutputToContain('Aucun rappel prévu aujourd\'hui.');

    Notification::assertNothingSent();
});

test('pas de rappel pour un mois clôturé, une maison archivée ou un compte non vérifié', function (): void {
    $other = User::factory()->unverified()->create();
    House::factory()->for($other, 'owner')->create();

    $this->house->delete();
    $this->artisan('compteurs:rappel-releves', ['--date' => '2026-11-30'])->assertSuccessful();

    Notification::assertNothingSent();
});

test('la commande est planifiée chaque jour à 8 h, heure du Bénin', function (): void {
    $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains($e->command, 'compteurs:rappel-releves'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('0 8 * * *')
        ->and($event->timezone)->toBe('Africa/Porto-Novo');
});
