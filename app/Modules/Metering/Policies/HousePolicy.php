<?php

declare(strict_types=1);

namespace App\Modules\Metering\Policies;

use App\Models\House;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Isolation stricte par propriétaire (ENF-09). Une maison d'un autre propriétaire répond
 * « introuvable » (404) plutôt que « interdit » : on ne révèle pas qu'elle existe.
 */
final class HousePolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return true;
    }

    public function view(User $user, House $house): Response
    {
        return $this->owns($user, $house);
    }

    public function update(User $user, House $house): Response
    {
        return $this->owns($user, $house);
    }

    public function delete(User $user, House $house): Response
    {
        return $this->owns($user, $house);
    }

    private function owns(User $user, House $house): Response
    {
        return $house->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
