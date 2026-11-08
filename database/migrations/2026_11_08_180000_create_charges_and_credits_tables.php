<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Résultats figés à la clôture (EF-32, CONCEPTION.md § 4.2) : participations par payeur
     * (charges), lots de crédit de consommation, mouvements de crédit, soldes de sortie.
     * Ces lignes ne sont écrites qu'à la clôture (§ 4.3) ; noms d'index courts pour MySQL.
     */
    public function up(): void
    {
        Schema::create('charges', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('utility_bill_id')->constrained()->cascadeOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('occupancy_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('kind', 10);
            $table->decimal('raw_consumption', 12, 3);
            $table->decimal('credit_applied', 12, 3)->default(0);
            $table->decimal('consumption', 12, 3);
            $table->boolean('is_estimated')->default(false);
            $table->boolean('includes_regularization')->default(false);
            $table->string('theoretical_amount', 64);
            $table->integer('rounding_adjustment')->default(0);
            $table->unsignedBigInteger('amount');
            $table->unsignedBigInteger('occupancy_key')->storedAs('coalesce(occupancy_id, 0)');
            $table->timestamps();

            $table->unique(['utility_bill_id', 'unit_id', 'occupancy_key'], 'charges_unique_payer');
        });

        Schema::create('consumption_credits', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occupancy_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('utility', 20);
            $table->foreignId('origin_billing_period_id')->constrained('billing_periods')->restrictOnDelete();
            $table->foreignId('created_in_billing_period_id')->constrained('billing_periods')->cascadeOnDelete();
            $table->decimal('quantity', 12, 3);
            $table->string('unit_price', 64);
            $table->timestamps();

            $table->index(['unit_id', 'utility', 'occupancy_id'], 'credits_unit_utility_payer');
        });

        Schema::create('credit_movements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('consumption_credit_id')->constrained()->cascadeOnDelete();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 10);
            $table->decimal('quantity', 12, 3);
            $table->string('value', 64)->nullable();
            $table->timestamps();
        });

        Schema::create('credit_settlements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('occupancy_id')->unique()->constrained()->restrictOnDelete();
            $table->foreignId('billing_period_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('statement_total');
            $table->unsignedBigInteger('credit_value');
            $table->unsignedBigInteger('deducted_amount');
            $table->unsignedBigInteger('refund_due');
            $table->timestamp('refunded_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('credit_settlements');
        Schema::dropIfExists('credit_movements');
        Schema::dropIfExists('consumption_credits');
        Schema::dropIfExists('charges');
    }
};
