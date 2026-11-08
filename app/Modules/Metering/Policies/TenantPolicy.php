<?php

declare(strict_types=1);

namespace App\Modules\Metering\Policies;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Access\Response;

/**
 * Un locataire n'est visible que de son propriétaire (ENF-09), sinon « introuvable » (404).
 */
final class TenantPolicy
{
    public function view(User $user, Tenant $tenant): Response
    {
        return $this->owns($user, $tenant);
    }

    public function update(User $user, Tenant $tenant): Response
    {
        return $this->owns($user, $tenant);
    }

    public function delete(User $user, Tenant $tenant): Response
    {
        return $this->owns($user, $tenant);
    }

    private function owns(User $user, Tenant $tenant): Response
    {
        return $tenant->user_id === $user->id ? Response::allow() : Response::denyAsNotFound();
    }
}
