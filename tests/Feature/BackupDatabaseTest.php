<?php

declare(strict_types=1);

use App\Console\Commands\BackupDatabase;
use App\Models\Charge;
use App\Models\House;
use Database\Seeders\DemoSeeder;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;

// Déploiement, livrable 4 : sauvegarde quotidienne de la base, conservée 30 jours.

beforeEach(function (): void {
    File::deleteDirectory(storage_path(BackupDatabase::DIRECTORY));
});

afterEach(function (): void {
    File::deleteDirectory(storage_path(BackupDatabase::DIRECTORY));
});

function backups(): array
{
    return array_map(fn ($f) => $f->getFilename(), File::files(storage_path(BackupDatabase::DIRECTORY)));
}

test('la sauvegarde produit un fichier .sql.gz que l\'on peut restaurer à l\'identique', function (): void {
    $this->seed(DemoSeeder::class);

    $this->artisan('compteurs:sauvegarde', ['--motif' => 'avant-deploiement'])
        ->expectsOutputToContain('Sauvegarde : compteurs_')
        ->assertSuccessful();

    expect(backups())->toHaveCount(1)
        ->and(backups()[0])->toMatch('/^compteurs_\d{4}-\d{2}-\d{2}_\d{6}_avant-deploiement\.sql\.gz$/');

    $sql = gzdecode((string) file_get_contents(storage_path(BackupDatabase::DIRECTORY . '/' . backups()[0])));
    expect($sql)->toContain('CREATE TABLE "houses"')->toContain('INSERT INTO "charges"');

    // Restauration dans une base SQLite neuve : mêmes données.
    $restored = new PDO('sqlite::memory:');
    $restored->exec($sql);
    expect((int) $restored->query('SELECT COUNT(*) FROM charges')->fetchColumn())->toBe(Charge::query()->count())
        ->and($restored->query('SELECT name FROM houses')->fetchColumn())->toBe(House::query()->value('name'))
        ->and((int) $restored->query('SELECT SUM(amount) FROM charges')->fetchColumn())->toBe((int) Charge::query()->sum('amount'));
});

test('les sauvegardes de plus de 30 jours sont supprimées, les autres gardées', function (): void {
    $directory = storage_path(BackupDatabase::DIRECTORY);
    File::ensureDirectoryExists($directory);
    foreach (['compteurs_ancienne.sql.gz' => 31, 'compteurs_recente.sql.gz' => 29, 'autre-fichier.txt' => 90] as $name => $age) {
        File::put("{$directory}/{$name}", 'x');
        touch("{$directory}/{$name}", now()->subDays($age)->getTimestamp());
    }

    $this->artisan('compteurs:sauvegarde')->assertSuccessful();

    expect(backups())->not->toContain('compteurs_ancienne.sql.gz')
        ->toContain('compteurs_recente.sql.gz')
        ->toContain('autre-fichier.txt')
        ->toHaveCount(3);
});

test('la sauvegarde est planifiée chaque jour à 2 h 30, conservation 30 jours', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($e) => str_contains((string) $e->command, 'compteurs:sauvegarde'));

    expect($event)->not->toBeNull()
        ->and($event->expression)->toBe('30 2 * * *')
        ->and($event->command)->toContain('--jours=30')
        ->and($event->timezone)->toBe('Africa/Porto-Novo');
});
