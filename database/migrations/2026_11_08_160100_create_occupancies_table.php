<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Occupation d'un logement par un locataire (EF-07). Au plus une occupation en cours par
     * logement à une date donnée : vérifié dans StartOccupancy (règle inter-lignes).
     */
    public function up(): void
    {
        Schema::create('occupancies', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->foreignId('tenant_id')->constrained()->restrictOnDelete();
            $table->date('started_on');
            $table->date('ended_on')->nullable();
            $table->timestamps();

            $table->index(['unit_id', 'started_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('occupancies');
    }
};
