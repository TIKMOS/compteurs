<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Locataires d'un propriétaire (EF-06). Téléphone béninois normalisé +229, facultatif (H-45),
     * unique pour un même propriétaire.
     */
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('phone', 20)->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
