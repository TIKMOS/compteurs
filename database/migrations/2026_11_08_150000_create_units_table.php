<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Logements d'une maison (EF-03) : libellé unique dans la maison, archivage par soft delete.
     */
    public function up(): void
    {
        Schema::create('units', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->string('label', 60);
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['house_id', 'label']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('units');
    }
};
