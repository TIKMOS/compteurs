<?php

declare(strict_types=1);

namespace App\Modules\Metering\Notifications;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

/**
 * Rappel de relevé (UC-20) : le dernier jour du mois, puis relance le 3 du mois suivant si des
 * relevés manquent encore. Email en français, avec un lien vers la saisie de chaque maison.
 */
final class MonthEndReadingsReminder extends Notification
{
    /**
     * @param list<array{house: \App\Models\House, missing: int}> $houses
     */
    public function __construct(
        public readonly CarbonImmutable $month,
        public readonly array $houses,
        public readonly bool $isFollowUp,
    ) {}

    /**
     * @return list<string>
     */
    public function via(User $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $label = $this->month->settings(['locale' => 'fr'])->isoFormat('MMMM YYYY');

        $mail = (new MailMessage)
            ->subject(($this->isFollowUp ? 'Relevés manquants : ' : 'Relevés de fin de mois : ') . $label)
            ->greeting('Bonjour ' . $notifiable->name . ',')
            ->line($this->isFollowUp
                ? "Des relevés de {$label} ne sont pas encore saisis. Sans eux, le mois ne peut pas être clôturé."
                : "C'est le dernier jour du mois : pensez à relever vos décompteurs d'eau et d'électricité.");

        foreach ($this->houses as $row) {
            $mail->line(sprintf('• %s : %d relevé%s à saisir', $row['house']->name, $row['missing'], $row['missing'] > 1 ? 's' : ''));
        }

        $first = $this->houses[0]['house'];

        return $mail
            ->action(count($this->houses) > 1 ? 'Saisir les relevés' : 'Saisir ' . Str::ucfirst($label), route('periodes.show', [$first, $this->month->format('Y-m')]))
            ->line('Un décompteur inaccessible ? Vous pouvez demander une estimation depuis l\'écran de saisie.');
    }
}
