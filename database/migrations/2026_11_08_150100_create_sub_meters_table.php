<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Décompteurs d'un logement (EF-04), avec historique des remplacements (EF-18).
     *
     * Au plus un décompteur ACTIF par logement et par fluide : colonne générée `is_active`
     * (1 tant que le décompteur est posé, NULL une fois déposé) et clé unique
     * (unit_id, utility, is_active) ; plusieurs NULL ne se gênent pas. CONCEPTION.md § 4.2
     * prévoyait IF(removed_on IS NULL, 0, id), refusé par MySQL (colonne générée sur un
     * auto-incrément) : même garantie, cf. H-42.
     */
    public function up(): void
    {
        Schema::create('sub_meters', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('unit_id')->constrained()->restrictOnDelete();
            $table->string('utility', 20);
            $table->string('serial_number', 60)->nullable();
            $table->date('installed_on');
            $table->decimal('install_index', 12, 3);
            $table->date('removed_on')->nullable();
            $table->decimal('removal_index', 12, 3)->nullable();
            $table->foreignId('replaces_sub_meter_id')->nullable()->constrained('sub_meters')->restrictOnDelete();
            $table->boolean('is_active')->nullable()->storedAs('case when removed_on is null then 1 else null end');
            $table->timestamps();

            $table->unique(['unit_id', 'utility', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sub_meters');
    }
};
