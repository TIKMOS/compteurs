<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Paiements reçus des locataires (UC-18, CONCEPTION.md § 4.2) : rattachés à une occupation et
     * à un mois clôturé ; montants entiers en FCFA.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('occupancy_id')->constrained()->restrictOnDelete();
            $table->foreignId('billing_period_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method', 20);
            $table->string('note')->nullable();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamps();

            $table->index(['occupancy_id', 'billing_period_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
