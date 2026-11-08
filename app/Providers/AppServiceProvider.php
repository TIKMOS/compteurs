<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\House;
use App\Models\Unit;
use App\Modules\Metering\Policies\HousePolicy;
use App\Modules\Metering\Policies\UnitPolicy;
use Illuminate\Support\Facades\Gate;
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

        // Les policies vivent dans les modules (app/Modules/*/Policies, cf. docs/STACK.md).
        Gate::policy(House::class, HousePolicy::class);
        Gate::policy(Unit::class, UnitPolicy::class);
    }
}
