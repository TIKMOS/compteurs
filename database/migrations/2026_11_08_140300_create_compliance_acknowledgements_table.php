<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Confirmations de l'avertissement de conformité (EF-49, D-21) : écriture seule,
     * jamais modifiées ni supprimées (pas de updated_at ni de deleted_at).
     */
    public function up(): void
    {
        Schema::create('compliance_acknowledgements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->restrictOnDelete();
            $table->foreignId('house_id')->constrained()->restrictOnDelete();
            $table->foreignId('utility_pricing_id')->constrained()->restrictOnDelete();
            $table->string('utility', 20);
            $table->string('kind', 30);
            $table->text('notice_text');
            $table->timestamp('acknowledged_at');
            $table->string('ip_address', 45)->nullable();

            $table->index(['house_id', 'utility']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('compliance_acknowledgements');
    }
};
