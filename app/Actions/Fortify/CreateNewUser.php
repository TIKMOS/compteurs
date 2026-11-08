<?php

declare(strict_types=1);

namespace App\Actions\Fortify;

use App\Models\User;
use App\Shared\Support\BeninPhone;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Contracts\CreatesNewUsers;

final class CreateNewUser implements CreatesNewUsers
{
    use PasswordValidationRules;

    /**
     * Inscription d'un propriétaire : nom, email, téléphone béninois facultatif, mot de passe.
     *
     * @param array<string, string> $input
     *
     * @throws ValidationException
     */
    public function create(array $input): User
    {
        $phone = filled($input['phone'] ?? null) ? BeninPhone::normalize($input['phone']) : null;

        Validator::make(
            [...$input, 'phone' => $phone ?? ($input['phone'] ?? null)],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'string', 'email', 'max:255', Rule::unique(User::class)],
                'phone' => [
                    'nullable',
                    'string',
                    'regex:/^\+22901\d{8}$/',
                    Rule::unique(User::class),
                ],
                'password' => $this->passwordRules(),
            ],
            ['phone.regex' => 'Saisissez un numéro béninois à 10 chiffres commençant par 01 (ex. 01 97 00 00 00).'],
        )->validate();

        return User::create([
            'name' => $input['name'],
            // Minuscules : la connexion compare l'email en minuscules (FortifyServiceProvider).
            'email' => Str::lower($input['email']),
            'phone' => $phone,
            'password' => Hash::make($input['password']),
        ]);
    }
}
