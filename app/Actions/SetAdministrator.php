<?php

declare(strict_types=1);

namespace App\Actions;

use App\Models\User;
use App\Modules\Metering\Support\Journal;

/**
 * Nomme ou retire un administrateur (UC-21, H-79), et le trace dans le journal d'activité.
 */
final class SetAdministrator
{
    public function handle(User $user, bool $admin): void
    {
        if ($user->is_admin === $admin) {
            return;
        }

        $user->forceFill(['is_admin' => $admin])->save();

        Journal::record(
            $admin ? 'administrateur_nomme' : 'administrateur_retire',
            $admin ? "Compte {$user->email} nommé administrateur" : "Administration retirée au compte {$user->email}",
            $user,
        );
    }
}
