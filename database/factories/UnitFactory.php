<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\House;
use App\Models\Unit;
use App\Shared\Enums\Utility;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Logement sans décompteur ; état withSubMeters() pour les deux fluides.
 *
 * @extends Factory<Unit>
 */
final class UnitFactory extends Factory
{
    protected $model = Unit::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'house_id' => House::factory(),
            'label' => 'Chambre ' . fake()->unique()->numberBetween(1, 999),
        ];
    }

    public function withSubMeters(string $installIndex = '0', string $installedOn = '2026-08-01'): static
    {
        return $this->afterCreating(function (Unit $unit) use ($installIndex, $installedOn): void {
            foreach (Utility::cases() as $utility) {
                $unit->subMeters()->create([
                    'utility' => $utility,
                    'installed_on' => $installedOn,
                    'install_index' => $installIndex,
                ]);
            }
        });
    }
}
