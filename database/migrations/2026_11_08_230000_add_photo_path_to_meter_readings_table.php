<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Photo du décompteur jointe au relevé, comme preuve (lot 3) : fichier privé (disque local),
     * réduit à 1 280 px en JPEG à l'arrivée.
     */
    public function up(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->string('photo_path')->nullable()->after('estimation_basis');
        });
    }

    public function down(): void
    {
        Schema::table('meter_readings', function (Blueprint $table): void {
            $table->dropColumn('photo_path');
        });
    }
};
