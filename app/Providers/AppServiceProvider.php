<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Number;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        // Formats français et franc CFA par défaut pour Number::currency() / Number::format() (ENF-06).
        Number::useLocale((string) config('app.locale'));
        Number::useCurrency((string) config('app.currency'));
    }
}
