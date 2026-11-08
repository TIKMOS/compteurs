<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Compteur principal SBEE / SONEB : exactement un par fluide et par maison (EF-02).
     */
    public function up(): void
    {
        Schema::create('main_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('house_id')->constrained()->cascadeOnDelete();
            $table->string('utility', 20);
            $table->string('provider_reference', 60)->nullable();
            $table->timestamps();

            $table->unique(['house_id', 'utility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('main_meters');
    }
};
