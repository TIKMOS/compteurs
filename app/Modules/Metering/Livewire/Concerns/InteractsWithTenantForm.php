<?php

declare(strict_types=1);

namespace App\Modules\Metering\Livewire\Concerns;

use App\Models\Tenant;
use App\Shared\Support\BeninPhone;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * Nom et téléphone d'un locataire (EF-06) : téléphone béninois facultatif (H-45), unique
 * parmi les locataires du propriétaire, archivés compris.
 */
trait InteractsWithTenantForm
{
    public string $tenantName = '';

    public string $tenantPhone = '';

    /**
     * @throws ValidationException
     */
    protected function validateTenantFields(?Tenant $ignore = null): void
    {
        $this->tenantPhone = trim($this->tenantPhone);
        $normalized = $this->tenantPhone === '' ? null : BeninPhone::normalize($this->tenantPhone);

        $this->validate([
            'tenantName' => ['required', 'string', 'max:120'],
            'tenantPhone' => [
                'nullable',
                function (string $attribute, mixed $value, Closure $fail) use ($normalized, $ignore): void {
                    if ($value === '' || $value === null) {
                        return;
                    }

                    if ($normalized === null) {
                        $fail('Saisissez un numéro béninois à 10 chiffres commençant par 01 (ex. 01 97 00 00 00).');

                        return;
                    }

                    $taken = Tenant::withTrashed()
                        ->ownedBy(auth()->user())
                        ->where('phone', $normalized)
                        ->when($ignore !== null, fn ($q) => $q->whereKeyNot($ignore->id))
                        ->exists();

                    if ($taken) {
                        $fail('Un de vos locataires (archivé ou non) a déjà ce numéro.');
                    }
                },
            ],
        ], [], ['tenantName' => 'nom du locataire', 'tenantPhone' => 'téléphone']);
    }
}
