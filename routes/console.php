<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// UC-20 : rappel de relevé, chaque matin à 8 h (heure du Bénin). La commande décide elle-même
// s'il y a quelque chose à envoyer (dernier jour du mois, ou relance le 3).
// Sur Hostinger : cron `* * * * * php artisan schedule:run` (docs/STACK.md § 8).
Schedule::command('compteurs:rappel-releves')
    ->dailyAt('08:00')
    ->timezone('Africa/Porto-Novo')
    ->withoutOverlapping();
