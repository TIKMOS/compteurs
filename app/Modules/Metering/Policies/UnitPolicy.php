<?php

declare(strict_types=1);

namespace App\Modules\Metering\Policies;

use App\Models\Unit;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un logement appartient au propriétaire de sa maison (ENF-09) ; sinon « introuvable » (404).
 */
final class UnitPolicy
{
    public function view(User $user, Unit $unit): Response
    {
        return $this->owns($user, $unit);
    }

    public function update(User $user, Unit $unit): Response
    {
        return $this->owns($user, $unit);
    }

    public function delete(User $user, Unit $unit): Response
    {
        return $this->owns($user, $unit);
    }

    private function owns(User $user, Unit $unit): Response
    {
        $house = $unit->house()->withTrashed()->first();

        return $house !== null && $house->user_id === $user->id && ! $house->trashed()
            ? Response::allow()
            : Response::denyAsNotFound();
    }
}
