<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Période = un mois d'une maison (EF-13), brouillon puis clôturée. `month` vaut toujours
     * le 1er du mois (vérifié dans OpenBillingPeriod).
     */
    public function up(): void
    {
        Schema::create('billing_periods', function (Blueprint $table): void {
            $table->id();
            $table->ulid()->unique();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->date('month');
            $table->string('status', 10)->default('draft');
            $table->timestamp('closed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->restrictOnDelete();
            $table->timestamp('reopened_at')->nullable();
            $table->string('reopen_reason')->nullable();
            $table->timestamps();

            $table->unique(['house_id', 'month']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_periods');
    }
};
