<?php

declare(strict_types=1);

namespace App\Modules\Metering\Data;

use App\Shared\Support\Fraction;

/**
 * Lot de crédit de consommation d'un payeur, pour un logement et un fluide (EF-24 b).
 * Le crédit appartient au locataire (D-11), pas au décompteur.
 */
final readonly class CreditLot
{
    /**
     * @param int|null $occupancyId locataire propriétaire du crédit (null = propriétaire / vacant, H-20)
     * @param int $quantity quantité restante en millièmes
     * @param Fraction $unitPrice prix unitaire du mois surestimé
     * @param int|null $id identifiant en base, null pour un lot créé pendant ce calcul
     * @param string|null $ref référence stable d'un lot créé pendant ce calcul (« new:1 »), pour relier ses mouvements
     */
    public function __construct(
        public ?int $occupancyId,
        public int $quantity,
        public Fraction $unitPrice,
        public string $originMonth,
        public ?int $id = null,
        public ?string $ref = null,
    ) {}

    public static function payerKey(?int $occupancyId): string
    {
        return $occupancyId === null ? 'vacant' : "occ:{$occupancyId}";
    }

    /** Identifie le lot à travers ses états successifs (id en base, ou référence de création). */
    public function reference(): string
    {
        return $this->id !== null ? "db:{$this->id}" : ($this->ref ?? 'new');
    }

    public function withQuantity(int $quantity): self
    {
        return new self($this->occupancyId, $quantity, $this->unitPrice, $this->originMonth, $this->id, $this->ref);
    }

    /** Valeur exacte en FCFA d'une quantité de ce lot. */
    public function valueOf(int $quantity): Fraction
    {
        return $this->unitPrice->multiply($quantity)->divide(1000);
    }
}
