<?php

declare(strict_types=1);

use App\Models\House;
use App\Models\MeterReading;
use App\Models\Tenant;
use App\Models\Unit;
use App\Models\User;
use App\Modules\Metering\Actions\AttachReadingPhoto;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\CreateTenantAccessLink;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Exceptions\PeriodClosedException;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

// Lot 3, tranche 2 — photo du décompteur jointe au relevé de fin de mois, comme preuve.

beforeEach(function (): void {
    Storage::fake('local');
    CarbonImmutable::setTestNow('2026-11-05 10:00:00');
    $this->owner = User::factory()->create();
    $this->actingAs($this->owner);
    $this->house = House::factory()->for($this->owner, 'owner')->create();
    $this->house->pricings()->update(['effective_from' => '2026-10-01']);
    $this->unit = Unit::factory()->for($this->house)->create(['label' => 'Chambre 1']);
    $this->meter = $this->unit->subMeters()->create(['utility' => Utility::Electricity, 'installed_on' => '2026-10-01', 'install_index' => '1000']);
    $this->tenant = Tenant::factory()->for($this->owner, 'owner')->create(['name' => 'A. Houngbédji']);
    app(StartOccupancy::class)->execute($this->unit->fresh(), $this->tenant, CarbonImmutable::parse('2026-10-01'), ['electricity' => '1000']);
    $this->october = app(OpenBillingPeriod::class)->execute($this->house, CarbonImmutable::parse('2026-10-01'));
    $this->reading = app(RecordMonthEndReading::class)->execute($this->october, $this->meter, '1100');
});

afterEach(fn () => CarbonImmutable::setTestNow());

test('la photo est réduite à 1 280 px en JPEG et stockée hors du dossier public', function (): void {
    app(AttachReadingPhoto::class)->execute($this->reading, UploadedFile::fake()->image('compteur.png', 4000, 3000));

    $path = $this->reading->fresh()->photo_path;
    expect($path)->toStartWith('photos/releves/' . $this->october->ulid . '/')
        ->and($path)->toEndWith('.jpg');
    Storage::disk('local')->assertExists($path);

    [$width, $height] = getimagesizefromstring(Storage::disk('local')->get($path));
    expect([$width, $height])->toBe([1280, 960]);
});

test('remplacer ou retirer une photo supprime l\'ancien fichier', function (): void {
    app(AttachReadingPhoto::class)->execute($this->reading, UploadedFile::fake()->image('a.jpg', 800, 600));
    $first = $this->reading->fresh()->photo_path;

    app(AttachReadingPhoto::class)->execute($this->reading->fresh(), UploadedFile::fake()->image('b.jpg', 800, 600));
    Storage::disk('local')->assertMissing($first);

    app(AttachReadingPhoto::class)->execute($this->reading->fresh(), null);
    expect($this->reading->fresh()->photo_path)->toBeNull()
        ->and(Storage::disk('local')->allFiles())->toBe([]);
});

test('effacer l\'index efface aussi sa photo', function (): void {
    app(AttachReadingPhoto::class)->execute($this->reading, UploadedFile::fake()->image('a.jpg', 800, 600));

    app(RecordMonthEndReading::class)->execute($this->october, $this->meter, null);

    expect(Storage::disk('local')->allFiles())->toBe([]);
});

test('écran de saisie : ajouter la photo depuis le téléphone, la voir', function (): void {
    Livewire::test('app.periodes.saisie', ['house' => $this->house, 'mois' => '2026-10'])
        ->assertSeeText('Ajouter une photo')
        ->set('photos.' . $this->meter->id, UploadedFile::fake()->image('compteur.jpg', 2000, 1500))
        ->assertHasNoErrors()
        ->assertSeeText('Voir la photo');

    $reading = $this->reading->fresh();
    $this->get(route('readings.photo', [$this->house, $reading->id]))
        ->assertOk()
        ->assertHeader('content-type', 'image/jpeg');
});

test('photo refusée : pas une image, ou mois clôturé', function (): void {
    Livewire::test('app.periodes.saisie', ['house' => $this->house, 'mois' => '2026-10'])
        ->set('photos.' . $this->meter->id, UploadedFile::fake()->create('releve.pdf', 100, 'application/pdf'))
        ->assertHasErrors('photos.' . $this->meter->id);

    app(RecordUtilityBill::class)->execute($this->october, Utility::Electricity, 10000, null);
    app(CloseBillingPeriod::class)->execute($this->october, $this->owner);

    expect(fn () => app(AttachReadingPhoto::class)->execute($this->reading->fresh(), UploadedFile::fake()->image('c.jpg')))
        ->toThrow(PeriodClosedException::class);
});

test('accès à la photo : propriétaire, locataire concerné par son lien ; pas les autres', function (): void {
    app(AttachReadingPhoto::class)->execute($this->reading, UploadedFile::fake()->image('a.jpg', 800, 600));
    app(RecordUtilityBill::class)->execute($this->october, Utility::Electricity, 10000, null);
    app(CloseBillingPeriod::class)->execute($this->october, $this->owner);

    $url = app(CreateTenantAccessLink::class)->execute($this->tenant, $this->owner);
    $token = basename($url);

    // Locataire : lien vers la photo sur sa fiche, et accès au fichier.
    $this->get($url)->assertSeeText('Photo du décompteur');
    $this->get(route('tenant.portal.photo', [$token, $this->reading->id]))->assertOk();

    // Un autre locataire de la maison, avec son propre lien : refusé.
    $other = Tenant::factory()->for($this->owner, 'owner')->create();
    $otherToken = basename(app(CreateTenantAccessLink::class)->execute($other, $this->owner));
    $this->get(route('tenant.portal.photo', [$otherToken, $this->reading->id]))->assertNotFound();

    // Un autre propriétaire : refusé.
    $this->actingAs(User::factory()->create())
        ->get(route('readings.photo', [$this->house, $this->reading->id]))->assertNotFound();

    expect(MeterReading::query()->whereNotNull('photo_path')->count())->toBe(1);
});
