<?php

declare(strict_types=1);

use App\Models\ComplianceAcknowledgement;
use App\Models\House;
use App\Models\User;
use App\Shared\Enums\AcknowledgementKind;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

// Lot 1, tranche 1 — UC-02 : maisons, compteurs principaux, mode de calcul à la création (EF-39),
// avertissement de conformité (EF-48, EF-49, T-51, T-53), isolation par propriétaire (ENF-09).

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-20 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('un invité est renvoyé vers la connexion', function (): void {
    auth()->logout();

    $this->get('/maisons')->assertRedirect('/login');
    $this->get('/maisons/nouvelle')->assertRedirect('/login');
});

test('les pages de la maison s\'affichent pour son propriétaire', function (): void {
    $house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);

    $this->get('/maisons')->assertOk()->assertSee('Cotonou Fidjrossè')->assertSee('répartition de la facture');
    $this->get('/maisons/nouvelle')->assertOk()->assertSee('Nouvelle maison');
    $this->get("/maisons/{$house->ulid}")->assertOk()->assertSee('Cotonou Fidjrossè')->assertSee('N° de police SONEB');
    $this->get("/maisons/{$house->ulid}/modifier")->assertOk()->assertSee('Modifier la maison');
    $this->get("/maisons/{$house->ulid}/parametres")->assertOk()->assertSee('Historique des prix');
});

test('création en mode 1 : deux compteurs principaux, deux tarifications, aucune confirmation', function (): void {
    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Cotonou Fidjrossè')
        ->set('address', 'Fidjrossè, Cotonou')
        ->set('references.electricity', 'SBEE-12345')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect();

    $house = House::query()->sole();
    expect($house->user_id)->toBe($this->owner->id)
        ->and($house->mainMeters)->toHaveCount(2)
        ->and($house->mainMeter(Utility::Electricity)->provider_reference)->toBe('SBEE-12345')
        ->and($house->mainMeter(Utility::Water)->provider_reference)->toBeNull()
        ->and($house->pricings)->toHaveCount(2);

    foreach (Utility::cases() as $utility) {
        $pricing = $house->currentPricing($utility);
        expect($pricing->mode)->toBe(PricingMode::BillSplit)
            ->and($pricing->effective_from->toDateString())->toBe('2026-11-01');
    }

    expect(ComplianceAcknowledgement::query()->count())->toBe(0);
});

test('T-51 création avec l\'eau en mode 2 plafonné : avertissement, puis une confirmation enregistrée', function (): void {
    $component = Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->set('modes.water', 'fixed_price')
        ->set('prices.water', '600')
        ->call('save')
        ->assertSet('confirmingCompliance', true)
        ->assertSee('interdisent à l\'abonné de revendre');

    expect(House::query()->count())->toBe(0);

    $component->call('confirmCompliance')->assertRedirect();

    $house = House::query()->sole();
    $water = $house->currentPricing(Utility::Water);
    expect($water->mode)->toBe(PricingMode::FixedPrice)
        ->and($water->fixed_unit_price)->toBe('600.00')
        ->and($water->cap_to_actual)->toBeTrue()
        ->and($house->currentPricing(Utility::Electricity)->mode)->toBe(PricingMode::BillSplit);

    $ack = ComplianceAcknowledgement::query()->sole();
    expect($ack->kind)->toBe(AcknowledgementKind::FixedPriceEnabled)
        ->and($ack->utility)->toBe(Utility::Water)
        ->and($ack->user_id)->toBe($this->owner->id)
        ->and($ack->utility_pricing_id)->toBe($water->id)
        ->and($ack->notice_text)->toBe(config('legal.compliance_notice'))
        ->and($ack->acknowledged_at->toDateTimeString())->toBe('2026-11-20 10:00:00')
        ->and($ack->ip_address)->not->toBeNull();
});

test('annuler l\'avertissement n\'enregistre rien', function (): void {
    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->set('modes.electricity', 'fixed_price')
        ->set('prices.electricity', '127,50')
        ->call('save')
        ->assertSet('confirmingCompliance', true)
        ->call('cancelCompliance')
        ->assertSet('confirmingCompliance', false);

    expect(House::query()->count())->toBe(0)
        ->and(ComplianceAcknowledgement::query()->count())->toBe(0);
});

test('T-53 création avec l\'eau en mode 2 sans plafond : un affichage, deux confirmations', function (): void {
    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->set('modes.water', 'fixed_price')
        ->set('prices.water', '650,5')
        ->set('caps.water', false)
        ->call('save')
        ->assertSet('complianceEvents', [
            'Activation du prix fixé (eau)',
            'Désactivation du plafond au prix réel (eau)',
        ])
        ->call('confirmCompliance');

    $house = House::query()->sole();
    expect($house->currentPricing(Utility::Water)->fixed_unit_price)->toBe('650.50')
        ->and($house->currentPricing(Utility::Water)->cap_to_actual)->toBeFalse()
        ->and(ComplianceAcknowledgement::query()->pluck('kind')->all())
        ->toEqualCanonicalizing([AcknowledgementKind::FixedPriceEnabled, AcknowledgementKind::CapDisabled])
        ->and(ComplianceAcknowledgement::query()->where('utility', 'electricity')->count())->toBe(0);
});

test('prix fixé invalide : message sous le champ', function (string $price): void {
    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->set('modes.electricity', 'fixed_price')
        ->set('prices.electricity', $price)
        ->call('save')
        ->assertHasErrors('prices.electricity')
        ->assertSet('confirmingCompliance', false);

    expect(House::query()->count())->toBe(0);
})->with(['vide' => '', 'zéro' => '0', 'trois décimales' => '127,505', 'texte' => 'cent', 'négatif' => '-5']);

test('le nom d\'une maison est unique pour un propriétaire, pas entre propriétaires', function (): void {
    House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    House::factory()->create(['name' => 'Maison Akpakpa']);

    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Cotonou Fidjrossè')
        ->call('save')
        ->assertHasErrors(['name' => 'unique']);

    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->call('save')
        ->assertHasNoErrors();
});

test('modifier le nom, l\'adresse et les références', function (): void {
    $house = House::factory()->for($this->owner, 'owner')->create();

    Livewire::test('app.maisons.modifier', ['house' => $house])
        ->set('name', 'Résidence Fidjrossè')
        ->set('address', '')
        ->set('references.water', 'SONEB-778')
        ->call('save')
        ->assertHasNoErrors()
        ->assertRedirect(route('maisons.show', $house));

    $house->refresh();
    expect($house->name)->toBe('Résidence Fidjrossè')
        ->and($house->address)->toBeNull()
        ->and($house->mainMeter(Utility::Water)->provider_reference)->toBe('SONEB-778');
});

test('archiver : la maison disparaît de la liste mais reste en base', function (): void {
    $house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Maison à archiver']);

    Livewire::test('app.maisons.detail', ['house' => $house])
        ->call('archive')
        ->assertRedirect(route('maisons.index'));

    expect(House::withTrashed()->find($house->id)->trashed())->toBeTrue();
    $this->get('/maisons')
        ->assertSeeText('La maison « Maison à archiver » est archivée.')
        ->assertDontSee("/maisons/{$house->ulid}");
    $this->get("/maisons/{$house->ulid}")->assertNotFound();
});

test('ENF-09 : un propriétaire ne voit ni ne modifie les maisons d\'un autre', function (): void {
    $other = House::factory()->create(['name' => 'Maison du voisin']);

    $this->get('/maisons')->assertDontSee('Maison du voisin');
    $this->get("/maisons/{$other->ulid}")->assertNotFound();
    $this->get("/maisons/{$other->ulid}/modifier")->assertNotFound();
    $this->get("/maisons/{$other->ulid}/parametres")->assertNotFound();

    Livewire::test('app.maisons.detail', ['house' => $other])->assertNotFound();
    Livewire::test('app.maisons.parametres', ['house' => $other])->assertNotFound();

    expect($other->fresh()->trashed())->toBeFalse();
});

test('une confirmation de conformité ne peut être ni modifiée ni supprimée', function (): void {
    Livewire::test('app.maisons.nouveau')
        ->set('name', 'Maison Akpakpa')
        ->set('modes.water', 'fixed_price')
        ->set('prices.water', '600')
        ->call('confirmCompliance');

    $ack = ComplianceAcknowledgement::query()->sole();

    expect(fn () => $ack->update(['ip_address' => '1.2.3.4']))->toThrow(LogicException::class)
        ->and(fn () => $ack->delete())->toThrow(LogicException::class);
});
