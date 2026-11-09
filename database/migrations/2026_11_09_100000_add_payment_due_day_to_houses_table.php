<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Jour limite de paiement des participations (H-76) : le N du mois qui suit le mois réparti.
     */
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table): void {
            $table->unsignedTinyInteger('payment_due_day')->default(10)->after('address');
        });
    }

    public function down(): void
    {
        Schema::table('houses', function (Blueprint $table): void {
            $table->dropColumn('payment_due_day');
        });
    }
};
