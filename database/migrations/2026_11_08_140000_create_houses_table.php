<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Maisons d'un propriétaire (EF-01). Archivage par soft delete : une maison qui a servi
     * à une clôture n'est jamais supprimée (CONCEPTION.md § 4.2).
     */
    public function up(): void
    {
        Schema::create('houses', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('address')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('houses');
    }
};
