<?php

declare(strict_types=1);

use App\Filament\Admin\Resources\Activities\ActivityResource;
use App\Filament\Admin\Resources\ComplianceAcknowledgements\ComplianceAcknowledgementResource;
use App\Filament\Admin\Resources\Houses\HouseResource;
use App\Filament\Admin\Resources\Users\Pages\ListUsers;
use App\Filament\Admin\Resources\Users\UserResource;
use App\Filament\Admin\Widgets\SupervisionOverview;
use App\Models\House;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

// UC-21 / H-79 : panneau d'administration /admin, réservé aux comptes is_admin, en lecture seule.

function admin(): User
{
    $admin = User::factory()->create();
    $admin->forceFill(['is_admin' => true])->save();

    return $admin;
}

test('un visiteur non connecté est renvoyé vers la connexion', function (): void {
    $this->get('/admin')->assertRedirect('/login');
});

test('un propriétaire ordinaire n\'entre pas dans le panneau', function (): void {
    $this->actingAs(User::factory()->create());

    $this->get('/admin')->assertForbidden();
    $this->get('/admin/users')->assertForbidden();
    $this->get('/admin/journal')->assertForbidden();
});

test('is_admin ne se remplit pas en masse (inscription, formulaire)', function (): void {
    $user = User::factory()->create();

    $user->fill(['is_admin' => true])->save();

    expect($user->refresh()->is_admin)->toBeFalse();
});

test('l\'administrateur voit le tableau de bord et les quatre listes, données de tous les comptes', function (): void {
    $this->seed(DemoSeeder::class);
    $this->actingAs(admin());

    $house = House::query()->firstOrFail();

    $this->get('/admin')->assertOk();
    Livewire::test(SupervisionOverview::class)->assertSeeText('Mois clôturés')->assertSeeText('Comptes');
    $this->get(UserResource::getUrl())->assertOk()->assertSeeText('demo@compteurs.test');
    $this->get(HouseResource::getUrl())->assertOk()->assertSeeText($house->name);
    $this->get(ComplianceAcknowledgementResource::getUrl())->assertOk();
    $this->get(ActivityResource::getUrl())->assertOk();
});

test('les ressources sont en lecture seule', function (): void {
    $this->actingAs(admin());
    $house = House::factory()->create();

    foreach ([UserResource::class, HouseResource::class, ComplianceAcknowledgementResource::class, ActivityResource::class] as $resource) {
        expect($resource::canViewAny())->toBeTrue()
            ->and($resource::canCreate())->toBeFalse()
            ->and($resource::canEdit($house))->toBeFalse()
            ->and($resource::canDelete($house))->toBeFalse()
            ->and($resource::canDeleteAny())->toBeFalse()
            ->and(array_keys($resource::getPages()))->toBe(['index']);
    }
});

test('nommer puis retirer un administrateur depuis le panneau, tracé au journal', function (): void {
    $admin = admin();
    $other = User::factory()->create();
    $this->actingAs($admin);

    Livewire::test(ListUsers::class)
        ->assertTableActionHidden('retirer', $admin)
        ->assertTableActionHidden('nommer', $admin)
        ->callTableAction('nommer', $other);

    expect($other->refresh()->is_admin)->toBeTrue()
        ->and(Activity::query()->where('event', 'administrateur_nomme')->sole()->causer?->is($admin))->toBeTrue();

    Livewire::test(ListUsers::class)->callTableAction('retirer', $other);

    expect($other->refresh()->is_admin)->toBeFalse()
        ->and(Activity::query()->where('event', 'administrateur_retire')->count())->toBe(1);
});

test('commande compteurs:admin : nomme, retire, refuse une adresse inconnue', function (): void {
    $user = User::factory()->create(['email' => 'koffi@example.com']);

    $this->artisan('compteurs:admin', ['email' => 'koffi@example.com'])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeTrue();

    $this->artisan('compteurs:admin', ['email' => 'koffi@example.com', '--retirer' => true])->assertSuccessful();
    expect($user->refresh()->is_admin)->toBeFalse();

    $this->artisan('compteurs:admin', ['email' => 'inconnu@example.com'])->assertFailed();
});

test('le lien « Administration » n\'apparaît que pour un administrateur', function (): void {
    $this->actingAs(User::factory()->create());
    $this->get('/maisons')->assertOk()->assertDontSeeText('Administration');

    $this->actingAs(admin());
    $this->get('/maisons')->assertOk()->assertSeeText('Administration');
});
