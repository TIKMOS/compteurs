<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\House;
use App\Models\User;
use App\Shared\Enums\PricingMode;
use App\Shared\Enums\Utility;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Maison complète : deux compteurs principaux et le mode 1 pour les deux fluides,
 * comme après CreateHouse.
 *
 * @extends Factory<House>
 */
final class HouseFactory extends Factory
{
    protected $model = House::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => 'Maison ' . fake()->unique()->city(),
            'address' => fake()->streetAddress(),
        ];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (House $house): void {
            foreach (Utility::cases() as $utility) {
                $house->mainMeters()->create(['utility' => $utility]);
                $house->pricings()->create([
                    'utility' => $utility,
                    'mode' => PricingMode::BillSplit,
                    'cap_to_actual' => true,
                    'effective_from' => CarbonImmutable::now()->startOfMonth(),
                    'created_by' => $house->user_id,
                ]);
            }
        });
    }
}
