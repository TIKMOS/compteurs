<?php

declare(strict_types=1);

use App\Models\BillingPeriod;
use App\Models\House;
use App\Models\MeterReading;
use App\Models\Occupancy;
use App\Models\Payment;
use App\Models\SubMeter;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CreateTenantAccessLink;
use App\Modules\Metering\Actions\RecordPayment;
use App\Shared\Enums\PaymentMethod;
use Carbon\CarbonImmutable;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\URL;
use Livewire\Livewire;

// Revue avant déploiement (ENF-09) : un propriétaire n'accède jamais aux données d'un autre,
// ni par l'URL, ni par une action Livewire à qui l'on passe l'identifiant d'un autre compte.

beforeEach(function (): void {
    $this->seed(DemoSeeder::class);
    $this->ownerA = User::query()->where('email', DemoSeeder::EMAIL)->sole();
    $this->houseA = House::query()->ownedBy($this->ownerA)->sole();
    $this->unitA = $this->houseA->units()->orderBy('id')->first();
    $this->occupancyA = Occupancy::query()->where('unit_id', $this->unitA->id)->sole();
    $this->tenantA = $this->occupancyA->tenant;
    $this->meterA = SubMeter::query()->where('unit_id', $this->unitA->id)->first();
    $this->readingA = MeterReading::query()->where('sub_meter_id', $this->meterA->id)->latest('id')->first();
    $this->periodA = BillingPeriod::query()->where('house_id', $this->houseA->id)->where('month', '2026-10-01')->sole();
    $this->paymentA = app(RecordPayment::class)->execute($this->occupancyA, $this->periodA, 1000, CarbonImmutable::parse('2026-11-05'), PaymentMethod::Cash, $this->ownerA, '');

    $this->ownerB = User::factory()->create();
    $this->houseB = House::factory()->for($this->ownerB, 'owner')->create();
    $this->unitB = Unit::factory()->for($this->houseB)->create();
    $this->actingAs($this->ownerB);
});

test('aucune page ni aucun document d\'un autre propriétaire n\'est accessible', function (): void {
    $h = $this->houseA->getRouteKey();
    $u = $this->unitA->getRouteKey();
    $urls = [
        "/maisons/{$h}",
        "/maisons/{$h}/modifier",
        "/maisons/{$h}/parametres",
        "/maisons/{$h}/logements/nouveau",
        "/maisons/{$h}/logements/{$u}",
        "/maisons/{$h}/logements/{$u}/entree",
        "/maisons/{$h}/logements/{$u}/sortie",
        "/maisons/{$h}/periodes/2026-10",
        "/maisons/{$h}/periodes/2026-10/recapitulatif",
        "/maisons/{$h}/periodes/2026-10/fiches/{$this->occupancyA->id}",
        "/maisons/{$h}/periodes/2026-10/recapitulatif.pdf",
        "/maisons/{$h}/sorties/{$this->occupancyA->id}/fiche",
        "/maisons/{$h}/paiements",
        "/maisons/{$h}/releves/{$this->readingA->id}/photo",
        "/maisons/{$h}/tableau-de-bord",
        "/maisons/{$h}/historique.csv",
        "/maisons/{$h}/historique.xlsx",
        "/locataires/{$this->tenantA->getRouteKey()}",
        // Sa propre maison, mais l'identifiant d'un objet d'un autre compte.
        "/maisons/{$this->houseB->getRouteKey()}/logements/{$u}",
        "/maisons/{$this->houseB->getRouteKey()}/logements/{$u}/entree",
        "/maisons/{$this->houseB->getRouteKey()}/logements/{$u}/sortie",
        "/maisons/{$this->houseB->getRouteKey()}/periodes/2026-10/fiches/{$this->occupancyA->id}",
        "/maisons/{$this->houseB->getRouteKey()}/sorties/{$this->occupancyA->id}/fiche",
        "/maisons/{$this->houseB->getRouteKey()}/releves/{$this->readingA->id}/photo",
    ];

    foreach ($urls as $url) {
        expect($this->get($url)->status())->toBe(404, $url);
    }

    // Les listes ne montrent que ses propres données.
    $this->get('/maisons')->assertOk()->assertDontSee($this->houseA->name);
    $this->get('/locataires')->assertOk()->assertDontSee($this->tenantA->name);
    $this->get('/journal')->assertOk()->assertDontSee($this->houseA->name);
});

test('les actions Livewire refusent les identifiants d\'un autre propriétaire', function (): void {
    $payments = fn () => Livewire::test('app.paiements.index', ['house' => $this->houseB]);
    $payments()->call('cancelPayment', $this->paymentA->id)->assertNotFound();
    $payments()->call('markRefunded', 1)->assertNotFound();
    $payments()->call('startPayment', $this->occupancyA->id, $this->periodA->id, 0)
        ->set('amount', '500')->set('paidOn', '2026-11-05')->call('savePayment')->assertNotFound();
    expect(Payment::query()->count())->toBe(1);

    $sheet = fn () => Livewire::test('app.periodes.saisie', ['house' => $this->houseB, 'mois' => '2026-11']);
    $sheet()->set("indexes.{$this->meterA->id}", '99999')->assertNotFound();
    $sheet()->call('estimate', $this->meterA->id)->assertNotFound();
    $sheet()->call('startReplacement', $this->meterA->id)->assertNotFound();
    $sheet()->call('removePhoto', $this->meterA->id)->assertNotFound();
    expect(MeterReading::query()->where('index_value', '99999.000')->exists())->toBeFalse();

    // Entrée dans son logement avec le locataire d'un autre compte : refusée par la validation.
    Livewire::test('app.logements.entree', ['house' => $this->houseB, 'unit' => $this->unitB])
        ->set('tenantChoice', 'existing')
        ->set('tenantId', $this->tenantA->id)
        ->call('save')
        ->assertHasErrors('tenantId');
    expect(Occupancy::query()->where('tenant_id', $this->tenantA->id)->count())->toBe(1);

    // Propriétés verrouillées : on ne peut pas remplacer la maison d'un composant.
    expect(fn () => Livewire::test('app.paiements.index', ['house' => $this->houseB])->set('house', $this->houseA->id))
        ->toThrow(Exception::class);
});

test('les liens locataire et les liens signés ne s\'ouvrent que sur leur propre fiche', function (): void {
    auth()->logout();
    $url = app(CreateTenantAccessLink::class)->execute($this->tenantA, $this->ownerA);
    $token = basename($url);
    $other = Occupancy::query()->where('tenant_id', '!=', $this->tenantA->id)->first();

    $this->get("/locataire/{$token}")->assertOk();
    $this->get("/locataire/{$token}/fiche/{$other->id}/2026-10")->assertNotFound();
    $this->get('/locataire/' . str_repeat('a', 48))->assertNotFound();

    $signed = URL::temporarySignedRoute('fiches.partage', now()->addDays(7), ['occupancy' => $this->occupancyA->id, 'mois' => '2026-10']);
    $this->get($signed)->assertOk();
    $this->get(str_replace("/fiche/{$this->occupancyA->id}/", "/fiche/{$other->id}/", $signed))->assertForbidden();
});

test('un visiteur non connecté est renvoyé vers la connexion', function (): void {
    auth()->logout();
    $this->get("/maisons/{$this->houseA->getRouteKey()}")->assertRedirect('/login');
    $this->get("/maisons/{$this->houseA->getRouteKey()}/historique.csv")->assertRedirect('/login');
});
