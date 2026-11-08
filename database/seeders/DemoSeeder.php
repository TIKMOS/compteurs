<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\House;
use App\Models\Tenant;
use App\Models\User;
use App\Modules\Metering\Actions\CloseBillingPeriod;
use App\Modules\Metering\Actions\CreateHouse;
use App\Modules\Metering\Actions\CreateUnit;
use App\Modules\Metering\Actions\OpenBillingPeriod;
use App\Modules\Metering\Actions\RecordMonthEndReading;
use App\Modules\Metering\Actions\RecordUtilityBill;
use App\Modules\Metering\Actions\StartOccupancy;
use App\Modules\Metering\Data\PricingRule;
use App\Modules\Metering\Data\SubMeterInstallation;
use App\Modules\Metering\Exceptions\IncompletePeriodException;
use App\Shared\Enums\PeriodStatus;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Données de démonstration, rejouables sans doublon : un propriétaire et la maison
 * « Cotonou Fidjrossè » de CONCEPTION.md § 5.
 *
 * 4 logements et leurs décompteurs (tranche 2), 4 locataires (tranche 3), 3 mois de relevés
 * et de factures, août à octobre 2026 (tranche 4), clôturés (tranche 5) ; novembre est à saisir.
 *
 *   php artisan db:seed --class=DemoSeeder
 *   Connexion : demo@compteurs.test (ou 01 97 00 00 00) / password
 */
final class DemoSeeder extends Seeder
{
    public const EMAIL = 'demo@compteurs.test';

    public function run(CreateHouse $createHouse): void
    {
        $owner = User::query()->firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Propriétaire de démonstration',
                'phone' => '+2290197000000',
                'password' => Hash::make('password'),
            ],
        );
        $owner->forceFill([
            'email_verified_at' => $owner->email_verified_at ?? now(),
            'terms_version' => config('legal.terms_version'),
            'terms_accepted_at' => $owner->terms_accepted_at ?? now(),
        ])->save();

        $house = House::withTrashed()->ownedBy($owner)->where('name', 'Cotonou Fidjrossè')->first()
            ?? $createHouse->execute(
                owner: $owner,
                name: 'Cotonou Fidjrossè',
                address: 'Fidjrossè, Cotonou — derrière la pharmacie',
                providerReferences: ['electricity' => 'SBEE 0412-7781', 'water' => 'SONEB 33-10598'],
                pricing: ['electricity' => PricingRule::billSplit(), 'water' => PricingRule::billSplit()],
            );

        $this->seedUnits($house);
        $this->seedOccupancies($owner, $house);
        $this->seedMonths($house);
        $this->closeMonths($owner, $house);
    }

    /**
     * Clôture août, septembre et octobre (dans l'ordre) : novembre peut alors être estimé sur un
     * vrai historique. Un mois incomplet (données locales modifiées) est laissé en brouillon.
     */
    private function closeMonths(User $owner, House $house): void
    {
        $periods = $house->billingPeriods()
            ->whereIn('month', ['2026-08-01', '2026-09-01', '2026-10-01'])
            ->where('status', PeriodStatus::Draft)
            ->orderBy('month')
            ->get();

        foreach ($periods as $period) {
            try {
                app(CloseBillingPeriod::class)->execute($period, $owner);
            } catch (IncompletePeriodException $e) {
                $this->command?->warn("{$period->label()} reste en brouillon : " . implode(' ', $e->problems));

                return;
            }
        }
    }

    /**
     * Index de fin de mois et factures d'août à octobre 2026 (les mois restent en brouillon ;
     * la clôture vient avec le calcul). Octobre reprend les index de CONCEPTION.md § 5.2.
     */
    private function seedMonths(House $house): void
    {
        $indexes = [
            '2026-08' => ['Chambre 1' => [1116, 402], 'Chambre 2' => [3382, 181], 'Chambre 3' => [492, 946], 'Boutique' => [5040, null]],
            '2026-09' => ['Chambre 1' => [1250, 412], 'Chambre 2' => [3480, 188], 'Chambre 3' => [702, 960], 'Boutique' => [5090, null]],
            '2026-10' => ['Chambre 1' => [1392, 421], 'Chambre 2' => [3578, 195], 'Chambre 3' => [917, 974], 'Boutique' => [5150, null]],
        ];
        $bills = [
            '2026-08' => [64200, 17900, null],
            '2026-09' => [63900, 18800, null],
            '2026-10' => [70000, 18505, '540'],
        ];

        foreach ($indexes as $month => $units) {
            // Mois déjà clôturé (seeder rejoué) : figé, on n'y touche plus.
            if ($house->billingPeriods()->where('month', $month . '-01')->where('status', PeriodStatus::Closed)->exists()) {
                continue;
            }

            $period = app(OpenBillingPeriod::class)->execute($house, CarbonImmutable::parse($month . '-01'));

            foreach ($units as $label => [$electricity, $water]) {
                $unit = $house->units()->with('subMeters')->where('label', $label)->first();
                foreach (['electricity' => $electricity, 'water' => $water] as $utility => $index) {
                    $meter = $unit?->activeSubMeter(Utility::from($utility));
                    if ($meter === null || $index === null || $meter->readings()->where('billing_period_id', $period->id)->where('kind', 'month_end')->exists()) {
                        continue;
                    }
                    app(RecordMonthEndReading::class)->execute($period, $meter, $index . '.000');
                }
            }

            [$electricityBill, $waterBill, $mainConsumption] = $bills[$month];
            if (! $period->bills()->exists()) {
                app(RecordUtilityBill::class)->execute($period, Utility::Electricity, $electricityBill, $mainConsumption === null ? null : $mainConsumption . '.000');
                app(RecordUtilityBill::class)->execute($period, Utility::Water, $waterBill, null);
            }
        }
    }

    /**
     * Locataires de CONCEPTION.md § 5, entrés le jour de la pose ; la Boutique est vacante en
     * août (consommation au propriétaire, EF-10) puis louée à partir du 01/09/2026.
     */
    private function seedOccupancies(User $owner, House $house): void
    {
        $occupants = [
            'Chambre 1' => ['A. Houngbédji', '+2290197000101', '2026-08-01', ['electricity' => '966', 'water' => '394']],
            'Chambre 2' => ['B. Dossou', '+2290197000102', '2026-08-01', ['electricity' => '3290', 'water' => '174']],
            'Chambre 3' => ['C. Agossou', '+2290197000103', '2026-08-01', ['electricity' => '287', 'water' => '932']],
            'Boutique' => ['M. Hounkpè', '+2290197000104', '2026-09-01', ['electricity' => '5040']],
        ];

        foreach ($occupants as $label => [$name, $phone, $date, $indexes]) {
            $unit = $house->units()->where('label', $label)->first();
            if ($unit === null || $unit->occupancies()->exists()) {
                continue;
            }

            $tenant = Tenant::query()->firstOrCreate(
                ['user_id' => $owner->id, 'phone' => $phone],
                ['name' => $name],
            );

            app(StartOccupancy::class)->execute($unit, $tenant, CarbonImmutable::parse($date), $indexes);
        }
    }

    /**
     * 4 logements, décompteurs posés le 01/08/2026 : les index de pose mènent aux index de
     * début d'octobre de CONCEPTION.md § 5.2 après les consommations d'août et de septembre.
     * La boutique n'a pas de décompteur d'eau (EF-05).
     */
    private function seedUnits(House $house): void
    {
        $installedOn = CarbonImmutable::parse('2026-08-01');
        $units = [
            'Chambre 1' => ['electricity' => '966', 'water' => '394'],
            'Chambre 2' => ['electricity' => '3290', 'water' => '174'],
            'Chambre 3' => ['electricity' => '287', 'water' => '932'],
            'Boutique' => ['electricity' => '5000', 'water' => null],
        ];

        foreach ($units as $label => $indexes) {
            if ($house->units()->withTrashed()->where('label', $label)->exists()) {
                continue;
            }

            app(CreateUnit::class)->execute($house, $label, array_map(
                fn (?string $index): ?SubMeterInstallation => $index === null
                    ? null
                    : new SubMeterInstallation($installedOn, $index . '.000'),
                $indexes,
            ));
        }
    }
}
