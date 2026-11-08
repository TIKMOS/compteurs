<?php

declare(strict_types=1);

namespace App\Modules\Metering\Console;

use App\Models\User;
use App\Modules\Metering\Notifications\MonthEndReadingsReminder;
use App\Modules\Metering\Queries\MissingMonthEndReadings;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Rappel de relevé (UC-20), lancé chaque jour par le planificateur : le dernier jour du mois
 * pour le mois en cours, le 3 du mois pour le mois précédent s'il reste des relevés à faire.
 */
final class SendReadingReminders extends Command
{
    public const FOLLOW_UP_DAY = 3;

    protected $signature = 'compteurs:rappel-releves {--date= : date du jour à simuler, AAAA-MM-JJ}';

    protected $description = 'Envoie les rappels de relevé de fin de mois aux propriétaires (UC-20)';

    public function handle(MissingMonthEndReadings $missing): int
    {
        $today = $this->option('date') !== null
            ? CarbonImmutable::parse((string) $this->option('date'))
            : CarbonImmutable::now(config('app.timezone'));

        if ($today->isLastOfMonth()) {
            $month = $today->startOfMonth();
            $isFollowUp = false;
        } elseif ($today->day === self::FOLLOW_UP_DAY) {
            $month = $today->subMonthNoOverflow()->startOfMonth();
            $isFollowUp = true;
        } else {
            $this->info('Aucun rappel prévu aujourd\'hui.');

            return self::SUCCESS;
        }

        $sent = 0;
        User::query()->whereNotNull('email_verified_at')->whereHas('houses')->each(function (User $owner) use ($missing, $month, $isFollowUp, &$sent): void {
            $houses = $missing->forOwner($owner, $month);
            if ($houses !== []) {
                $owner->notify(new MonthEndReadingsReminder($month, $houses, $isFollowUp));
                $sent++;
            }
        });

        $this->info("{$sent} rappel(s) envoyé(s) pour " . $month->locale('fr')->isoFormat('MMMM YYYY') . '.');

        return self::SUCCESS;
    }
}
