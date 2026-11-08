<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Versions de tarification par maison et par fluide, avec mois d'effet (EF-46, D-18, D-24).
     * Les CHECK de CONCEPTION.md § 4.2 (1er du mois, prix ⇔ mode 2, prix > 0) sont vérifiés
     * dans l'Action ChangeUtilityPricing : Laravel ne les exprime pas de façon portable
     * entre MySQL et SQLite.
     */
    public function up(): void
    {
        Schema::create('utility_pricings', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('house_id')->constrained()->cascadeOnDelete();
            $table->string('utility', 20);
            $table->string('mode', 20)->default('bill_split');
            $table->decimal('fixed_unit_price', 10, 2)->nullable();
            $table->boolean('cap_to_actual')->default(true);
            $table->date('effective_from');
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->unique(['house_id', 'utility', 'effective_from']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('utility_pricings');
    }
};
