<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Relevé = un index lu (ou estimé) sur un décompteur à une date (CONCEPTION.md § 4.2).
     * Clé unique (décompteur, période, type, occupation) : un relevé de fin de mois par
     * décompteur et par mois, un relevé d'entrée et de sortie par occupation. `occupancy_key`
     * (0 sans occupation) permet cette clé malgré les NULL.
     */
    public function up(): void
    {
        Schema::create('meter_readings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('sub_meter_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('occupancy_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 20);
            $table->date('read_on');
            $table->decimal('index_value', 12, 3);
            $table->boolean('is_estimated')->default(false);
            $table->string('estimation_basis')->nullable();
            $table->unsignedBigInteger('occupancy_key')->storedAs('coalesce(occupancy_id, 0)');
            $table->timestamps();

            // Noms courts : MySQL limite les identifiants à 64 caractères.
            $table->unique(['sub_meter_id', 'billing_period_id', 'kind', 'occupancy_key'], 'meter_readings_unique_reading');
            $table->index(['sub_meter_id', 'read_on'], 'meter_readings_meter_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meter_readings');
    }
};
