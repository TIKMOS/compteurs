<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Support\Facades\Notification;

// ENF-05 : les emails d'authentification sont en français (traductions dans lang/fr.json).

test('l\'email de vérification est en français', function (): void {
    $user = User::factory()->unverified()->create();

    $mail = (new VerifyEmail)->toMail($user);
    $html = (string) $mail->render();

    expect($mail->subject)->toBe('Vérifiez votre adresse email')
        ->and($mail->actionText)->toBe('Confirmer mon adresse email')
        ->and($html)->toContain('Bonjour,')
        ->toContain('Cordialement,')
        ->toContain('copiez le lien ci-dessous')
        ->not->toContain('Verify')
        ->not->toContain('Regards');
});

test('l\'email de mot de passe oublié est en français', function (): void {
    Notification::fake();
    $user = User::factory()->create(['email' => 'ama@example.com']);

    $this->post('/forgot-password', ['email' => 'ama@example.com'])->assertSessionHasNoErrors();

    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user): bool {
        $mail = $notification->toMail($user);

        return $mail->subject === 'Réinitialisez votre mot de passe'
            && $mail->actionText === 'Choisir un nouveau mot de passe'
            && in_array('Ce lien expire dans 60 minutes.', array_merge($mail->introLines, $mail->outroLines), true);
    });
});
