<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Facture d'un fluide pour une période (EF-19, EF-20, CONCEPTION.md § 4.2).
     *
     * Saisie en brouillon : montant TTC (facultatif en mode 2 non plafonné) et consommation du
     * compteur principal (information). Les colonnes « figées » (tarification, prix réel,
     * prix appliqué…) sont remplies à la clôture (tranche 5) et restent nulles en brouillon.
     */
    public function up(): void
    {
        Schema::create('utility_bills', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->foreignId('main_meter_id')->constrained()->restrictOnDelete();
            $table->string('utility', 20);
            $table->unsignedBigInteger('total_amount')->nullable();
            $table->decimal('main_consumption', 12, 3)->nullable();

            // Figé à la clôture (EF-32).
            $table->foreignId('utility_pricing_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('pricing_mode', 20)->nullable();
            $table->decimal('fixed_unit_price', 10, 2)->nullable();
            $table->boolean('cap_to_actual')->nullable();
            $table->decimal('total_consumption', 12, 3)->nullable();
            $table->string('actual_unit_price', 64)->nullable();
            $table->string('unit_price', 64)->nullable();
            $table->string('price_source', 10)->nullable();
            $table->timestamps();

            $table->unique(['billing_period_id', 'utility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_bills');
    }
};
