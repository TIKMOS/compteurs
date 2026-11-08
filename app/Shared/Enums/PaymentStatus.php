<?php

declare(strict_types=1);

namespace App\Shared\Enums;

/**
 * Statut du paiement d'une répartition clôturée pour un locataire (UC-18) : rien reçu, une
 * partie reçue, ou tout reçu. Un montant dû nul (crédit qui couvre tout) est « payé ».
 */
enum PaymentStatus: string
{
    case Paid = 'paid';
    case Partial = 'partial';
    case Unpaid = 'unpaid';

    public static function for(int $due, int $paid): self
    {
        if ($paid >= $due) {
            return self::Paid;
        }

        return $paid > 0 ? self::Partial : self::Unpaid;
    }

    public function label(): string
    {
        return match ($this) {
            self::Paid => 'Payé',
            self::Partial => 'Partiel',
            self::Unpaid => 'Impayé',
        };
    }

    /** Ton du badge (composant <x-ui.badge>). */
    public function tone(): string
    {
        return match ($this) {
            self::Paid => 'success',
            self::Partial => 'warning',
            self::Unpaid => 'danger',
        };
    }
}
