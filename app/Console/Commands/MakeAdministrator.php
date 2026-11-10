<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\SetAdministrator;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Nomme (ou retire) un administrateur du panneau /admin (UC-21, H-79). Le compte doit déjà
 * exister : il se crée par l'inscription normale.
 *
 *   php artisan compteurs:admin proprietaire@example.com
 *   php artisan compteurs:admin proprietaire@example.com --retirer
 */
final class MakeAdministrator extends Command
{
    protected $signature = 'compteurs:admin
        {email : Adresse e-mail du compte}
        {--retirer : Retire l\'accès au panneau d\'administration}';

    protected $description = 'Donne ou retire à un compte l\'accès au panneau d\'administration /admin';

    public function handle(SetAdministrator $setAdministrator): int
    {
        $email = (string) $this->argument('email');
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            $this->error("Aucun compte avec l'adresse {$email}.");

            return self::FAILURE;
        }

        $admin = ! $this->option('retirer');
        $setAdministrator->handle($user, $admin);

        $this->info($admin
            ? "{$user->name} ({$email}) est administrateur : panneau accessible sur /admin."
            : "{$user->name} ({$email}) n'est plus administrateur.");

        return self::SUCCESS;
    }
}
