<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\Tenant;
use App\Models\TenantAccessLink;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\CreateTenantAccessLink;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\RevokeTenantAccessLink;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Livewire\Livewire;
use Spatie\LaravelPdf\Facades\Pdf;

// Lot 3, tranche 1 — EF-38 / UC-19 : lien personnel du locataire, révocable, lecture seule.

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-12-05 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create(['name' => 'Cotonou Fidjrossè']);
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);

    $this->tenants = [];
    $this->occ = [];
    foreach (['Chambre 1' => 'A. Houngbédji', 'Chambre 2' => 'B. Dossou'] as $label => $name) {
        $unit = Unit::factory()->for($this->house)->create(['label' => $label]);
        $unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1000']);
        $this->tenants[$label] = Tenant::factory()->for($this->owner, 'owner')->create(['name' => $name]);
        $this->occ[$label] = app(StartOccupancy::class)->execute($unit->fresh(), $this->tenants[$label], CarbonImmutable::parse('2026-10-01'), ['electricity' => '1000']);
        $this->units[$label] = $unit;
    }

    // Octobre clôturé (A : 100 kWh, B : 50 kWh, 15 000 FCFA) ; novembre en brouillon.
    $october = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    app(RecordMonthEndReading::class)->execute($october, $this->units['Chambre 1']->fresh('subMeters')->activeSubMeter(Utility::Electricity), '1100');
    app(RecordMonthEndReading::class)->execute($october, $this->units['Chambre 2']->fresh('subMeters')->activeSubMeter(Utility::Electricity), '1050');
    app(RecordUtilityBill::class)->execute($october, Utility::Electricity, 15000, null);
    app(CloseBillingPeriod::class)->execute($october, $this->owner);
    app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-11-01'));
});

afterEach(fn () => CarbonImmutable::setTestNow());

function tokenOf(string $url): string
{
    return basename(parse_url($url, PHP_URL_PATH));
}

test('le locataire ouvre son lien sans compte et ne voit que ses fiches des mois clôturés', function (): void {
    $url = app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner);
    auth()->logout();

    $this->get($url)
        ->assertOk()
        ->assertSeeText('Bonjour A. Houngbédji')
        ->assertSeeText('Octobre 2026')
        ->assertSeeText("10\u{00A0}000 FCFA")
        ->assertDontSeeText('B. Dossou')
        ->assertDontSeeText('Novembre 2026');

    expect(TenantAccessLink::query()->sole()->last_used_at)->not->toBeNull();
});

test('seule l\'empreinte du jeton est stockée', function (): void {
    $url = app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner);
    $token = tokenOf($url);

    $link = TenantAccessLink::query()->sole();
    expect(strlen($token))->toBe(48)
        ->and($link->token_hash)->toBe(hash('sha256', $token))
        ->and($link->token_hash)->not->toContain($token);
});

test('révocation, nouveau lien qui désactive l\'ancien, jeton inconnu : 404', function (): void {
    $first = app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner);
    $second = app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner);

    $this->get($first)->assertNotFound();
    $this->get($second)->assertOk();

    app(RevokeTenantAccessLink::class)->execute($this->tenants['Chambre 1']);
    $this->get($second)->assertNotFound();
    $this->get('/locataire/' . str_repeat('a', 48))->assertNotFound();
});

test('PDF par le lien : sa fiche seulement, mois clôturé seulement', function (): void {
    Pdf::fake();
    $token = tokenOf(app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner));
    auth()->logout();

    $this->get(route('tenant.portal.pdf', [$token, $this->occ['Chambre 1']->id, '2026-10']))->assertOk();
    $this->get(route('tenant.portal.pdf', [$token, $this->occ['Chambre 2']->id, '2026-10']))->assertNotFound();
    $this->get(route('tenant.portal.pdf', [$token, $this->occ['Chambre 1']->id, '2026-11']))->assertNotFound();
});

test('le locataire sorti et archivé garde l\'accès à son historique', function (): void {
    $url = app(CreateTenantAccessLink::class)->execute($this->tenants['Chambre 1'], $this->owner);
    app(App\Modules\Metering\Actions\EndOccupancy::class)->execute($this->occ['Chambre 1'], CarbonImmutable::parse('2026-11-30'), ['electricity' => '1180']);
    $this->tenants['Chambre 1']->delete();

    $this->get($url)->assertOk()->assertSeeText('Octobre 2026');
});

test('fiche du locataire : création, lien affiché une fois avec WhatsApp, puis révocation', function (): void {
    $tenant = $this->tenants['Chambre 1'];

    $component = Livewire::test('app.locataires.detail', ['tenant' => $tenant])
        ->assertSeeText('Créer le lien personnel')
        ->call('createLink')
        ->assertSeeText('Lien créé. Envoyez-le maintenant')
        ->assertSee('https://wa.me/', false);

    expect($component->get('newLink'))->toStartWith(url('/locataire/'));

    Livewire::test('app.locataires.detail', ['tenant' => $tenant->fresh()])
        ->assertSeeText('Lien actif depuis le 05/12/2026')
        ->assertDontSeeText('Lien créé.')
        ->call('revokeLink')
        ->assertSeeText('Le lien personnel est révoqué');

    expect($tenant->fresh()->activeAccessLink())->toBeNull();
});
